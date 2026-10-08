<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use InvalidArgumentException;
use wmd\designfield\jobs\AdoptJob;
use wmd\designfield\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The "Tidy up" tab's actions: a block type's generated config, the agent brief, and
 * moving a block type onto the Design field (a dry run that checks every stored value,
 * then a queue job).
 *
 * @author WMD
 * @since 1.0.0
 */
class TidyController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @return Response
     * @throws BadRequestHttpException without a type
     * @throws ForbiddenHttpException for a user who is not an admin
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionConfig(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        return $this->asJson(['php' => Plugin::getInstance()->getTidy()->configFor((string)$this->request->getRequiredBodyParam('type'))]);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException for a request that is not a JSON POST
     * @throws ForbiddenHttpException for a user who is not an admin
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionBrief(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(false);
        $stack = $this->request->getBodyParam('stack') === 'graphql' ? 'graphql' : 'twig';

        return $this->asJson(['text' => Plugin::getInstance()->getTidy()->brief($stack)]);
    }

    /**
     * Without `apply`, a dry run: the fields that would move and what happens to their stored
     * values. With it, a queue job (field layouts change, so admin changes must be allowed).
     *
     * @return Response
     * @throws BadRequestHttpException for a type without a profile or a Design field, or with values that would be lost
     * @throws ForbiddenHttpException for a user who is not an admin, or when admin changes are off and `apply` is set
     *
     * @author WMD
     * @since 1.0.0
     */
    public function actionMove(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $type = (string)$this->request->getRequiredBodyParam('type');
        $apply = (bool)$this->request->getBodyParam('apply');
        $this->requireAdmin($apply);

        $plugin = Plugin::getInstance();
        if (!in_array($type, $plugin->getGroups()->getRegistry()->profileNames(), true)) {
            throw new BadRequestHttpException(Craft::t('design-field', '{type} has no profile yet: add its options first.', ['type' => $type]));
        }

        $field = $plugin->getTidy()->designFieldFor($type);
        if ($field === null) {
            throw new BadRequestHttpException(Craft::t('design-field', 'No Design field to move {type} into: add one to its layout first.', ['type' => $type]));
        }

        // Always look first, also before a real move: the job would stop anyway.
        try {
            $result = $plugin->getConverter()->adopt($type, $field, true);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }
        $report = $result['report'] ?? [];

        if (!$apply) {
            return $this->asJson([
                'status' => $result['status'],
                'old' => $result['old'],
                'entries' => (int)($report['scanned'] ?? 0),
                'changed' => (int)($report['changed'] ?? 0),
                'unknown' => $report['unknown'] ?? [],
            ]);
        }

        if (($report['unknown'] ?? []) !== []) {
            throw new BadRequestHttpException(Craft::t('design-field', 'Some stored values have no matching option; nothing was moved.'));
        }

        // Long moves (thousands of blocks) must not be released and run twice: an hour.
        Queue::push(new AdoptJob(['type' => $type, 'field' => $field]), null, null, 3600);

        return $this->asJson(['queued' => true]);
    }
}

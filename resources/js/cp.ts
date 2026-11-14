// Runs on every control-panel page before the panel boots: registers the Vue side of the
// Controls our PHP names (see src/web/controls). Each loads on the first panel that uses it.
import {registerComponent} from '@craftcms/cp';

registerComponent('design-field:steps', () => import('./StepsControl.vue'));
registerComponent('design-field:grid', () => import('./GridControl.vue'));
registerComponent('design-field:rendered', () => import('./RenderedControl.vue'));
registerComponent('design-field:presets', () => import('./PresetsControl.vue'));

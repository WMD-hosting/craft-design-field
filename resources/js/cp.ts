// Runs on every control-panel page before the panel boots: registers the Vue side of the
// Controls our PHP names (see src/web/controls). Loaded lazily, on the first panel that uses it.
import {registerComponent} from '@craftcms/cp';

registerComponent('design-field:steps', () => import('./StepsControl.vue'));

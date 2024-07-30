/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import {createApp} from 'vue';
import {createFlow} from "./flow";


export const main = createApp({});

main
    .use(
        createFlow({
            ...FlowOptions,
        }),
    ).mount(FlowOptions.mount || '#flow-app');


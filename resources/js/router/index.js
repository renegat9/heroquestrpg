import { createRouter, createWebHistory } from 'vue-router';

import AccueilView from '../views/AccueilView.vue';
import TableView from '../views/TableView.vue';
import ManetteView from '../views/ManetteView.vue';
import CreationGroupeView from '../views/CreationGroupeView.vue';
import SelectionQueteView from '../views/SelectionQueteView.vue';
import MonteeNiveauView from '../views/MonteeNiveauView.vue';
import ReconnexionView from '../views/ReconnexionView.vue';
import ClotureCampagneView from '../views/ClotureCampagneView.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        // ---- écrans portés des maquettes ----
        { path: '/', name: 'accueil', component: AccueilView },
        { path: '/table/:groupe', name: 'table', component: TableView, props: true },
        { path: '/manette/:groupe', name: 'manette', component: ManetteView, props: true },
        { path: '/direction', name: 'direction', component: CreationGroupeView },

        // ---- écrans en stub (maquettes dans reference/heroquest/) ----
        { path: '/quete/:groupe', name: 'selection-quete', component: SelectionQueteView, props: true },
        { path: '/niveau/:groupe', name: 'montee-niveau', component: MonteeNiveauView, props: true },
        { path: '/reconnexion/:groupe', name: 'reconnexion', component: ReconnexionView, props: true },
        { path: '/cloture/:groupe', name: 'cloture', component: ClotureCampagneView, props: true },

        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

export default router;

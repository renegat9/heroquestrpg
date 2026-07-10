import { createRouter, createWebHistory } from 'vue-router';

import AccueilView from '../views/AccueilView.vue';
import NarreurView from '../views/NarreurView.vue';
import JoueurView from '../views/JoueurView.vue';
import TableView from '../views/TableView.vue';
import ManetteView from '../views/ManetteView.vue';
import MonteeNiveauView from '../views/MonteeNiveauView.vue';
import ClotureCampagneView from '../views/ClotureCampagneView.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        // ---- accueil : choix de rôle ----
        { path: '/', name: 'accueil', component: AccueilView },

        // ---- rôle Narrateur (table, sans compte) ----
        { path: '/narrateur', name: 'narrateur', component: NarreurView },

        // ---- rôle Joueur (compte + roster) ----
        { path: '/joueur', name: 'joueur', component: JoueurView },

        // ---- écrans de jeu ----
        { path: '/table/:groupe', name: 'table', component: TableView, props: true },
        { path: '/manette/:groupe', name: 'manette', component: ManetteView, props: true },

        // ---- écrans de moments de campagne ----
        { path: '/niveau/:groupe', name: 'montee-niveau', component: MonteeNiveauView, props: true },
        { path: '/cloture/:groupe', name: 'cloture', component: ClotureCampagneView, props: true },

        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

export default router;

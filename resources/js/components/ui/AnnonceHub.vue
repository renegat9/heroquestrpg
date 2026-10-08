<script setup>
// Annonces de FIN DE QUÊTE au hub (chantier 1c, Wizards of Morcar, 2026-10-08) :
// l'ENTRETIEN des mercenaires et la FAVEUR de Hopekins Rest. Le serveur publie la
// DÉCISION — `groupe.mercenaires_entretien` et `groupe.faveur_hopekins`, déjà
// bornées à la dernière quête achevée. Ce composant n'en recalcule rien : ni le
// coût, ni qui part faute d'or, ni qui reçoit la faveur. Un seul rendu, partagé
// par l'écran de table et la manette.
import MSym from './MSym.vue';

defineProps({
    /** `groupe.mercenaires_entretien` : {cout_par_mercenaire, cout_total, or_restant, payes, partis} ou null. */
    entretien: { type: Object, default: null },
    /** `groupe.faveur_hopekins` : {personnage, faveur_libelle, faveur_effet} ou null. */
    faveur: { type: Object, default: null },
});

const noms = (liste) => (liste ?? []).map((m) => m.nom).join(', ');
</script>

<template>
    <div v-if="entretien || faveur" class="annonce-hub">
        <div v-if="entretien" class="annonce-hub-ligne">
            <MSym n="paid" fill :size="16" />
            <div class="annonce-hub-texte">
                <b>Entretien des mercenaires</b> : {{ entretien.cout_par_mercenaire }} po par mercenaire.
                <template v-if="entretien.payes?.length">
                    {{ entretien.cout_total }} po versées pour {{ noms(entretien.payes) }}.
                </template>
                <template v-if="entretien.partis?.length">
                    <b class="annonce-hub-parti">
                        Faute d'or, {{ noms(entretien.partis) }}
                        {{ entretien.partis.length > 1 ? 'quittent' : 'quitte' }} le groupe.
                    </b>
                </template>
                <span class="annonce-hub-or">Bourse commune : {{ entretien.or_restant }} po</span>
            </div>
        </div>
        <div v-if="faveur" class="annonce-hub-ligne">
            <MSym n="workspace_premium" fill :size="16" />
            <div class="annonce-hub-texte">
                <b>Faveur de Hopekins Rest</b> : {{ faveur.personnage }} reçoit « {{ faveur.faveur_libelle }} ».
                <span class="annonce-hub-effet">{{ faveur.faveur_effet }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Scopé : les blocs <style> globaux du projet font fuir les noms de classe
   d'une vue à l'autre — ces règles ne doivent sortir de ce composant. */
.annonce-hub { display: grid; gap: 8px; width: 100%; max-width: 560px; margin: 0 auto; text-align: left; }
.annonce-hub-ligne { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px;
  border: 1px solid var(--torch, #c9a25a); border-radius: 10px;
  background: rgba(201, 162, 90, 0.08); color: var(--ink-100, #f0e9d8);
  font-family: var(--font-ui, inherit); font-style: normal; font-size: 13.5px; line-height: 1.45; }
.annonce-hub-ligne > .msym { flex: none; margin-top: 1px; color: var(--torch, #c9a25a); }
.annonce-hub-texte { min-width: 0; }
.annonce-hub-parti { display: block; margin-top: 3px; color: var(--danger, #e0735c); }
.annonce-hub-or { display: block; margin-top: 3px; font-size: 12px; color: var(--ink-500, #9a9384); }
.annonce-hub-effet { display: block; margin-top: 3px; font-size: 12.5px; color: var(--ink-300, #cfc6b0); }
</style>

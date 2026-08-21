<script setup>
/**
 * Ouverture de quête plein cadre, sur l'écran de table.
 *
 * Deux temps dans un seul panneau, parce que c'est un seul moment pour le
 * groupe attablé :
 *
 *  1. PRÉPARATION — construire une quête prend une à deux minutes (habillage
 *     des monstres, illustration de scène, récits, voix). L'écran ne disait
 *     rien pendant ce temps : les joueurs attendaient devant un donjon muet
 *     sans savoir si ça avançait ou si tout était figé (René, 2026-08-21).
 *  2. OUVERTURE — l'illustration de scène en grand, avec le texte qui plante
 *     le donjon. Cette image existait déjà, mais n'apparaissait que dans une
 *     vignette de 56 px au coin du bandeau.
 *
 * Se ferme quand le narrateur a fini de lire (le parent le pilote via
 * `visible`), jamais toute seule : c'est la table qui donne le tempo.
 */
defineProps({
    /** Étape en cours `{etape, libelle, index, total}`, ou null si rien ne tourne. */
    preparation: { type: Object, default: null },
    /** Texte d'ouverture, une fois les récits écrits. */
    texte: { type: String, default: '' },
    /** Illustration de scène (`quete.image_url`), si elle a été générée. */
    image: { type: String, default: null },
    /** Titre de la quête, affiché en surtitre. */
    titre: { type: String, default: '' },
});
</script>

<template>
    <div class="ouv">
        <div class="ouv-carte">
            <img v-if="image" :src="image" alt="" class="ouv-img" />
            <div v-else class="ouv-img ouv-img-vide"></div>

            <div class="ouv-corps">
                <p v-if="titre" class="ouv-titre">{{ titre }}</p>

                <!-- 1) Préparation : on montre l'étape, pas un sablier muet. -->
                <template v-if="preparation">
                    <p class="ouv-etape">{{ preparation.libelle }}</p>
                    <div class="ouv-jauge" :aria-label="`Étape ${preparation.index} sur ${preparation.total}`">
                        <i
                            v-for="n in preparation.total"
                            :key="n"
                            :class="{ fait: n <= preparation.index }"
                        />
                    </div>
                </template>

                <!-- 2) Ouverture : le texte qui plante le donjon. -->
                <p v-else class="ouv-texte">{{ texte }}</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ouv {
    position: absolute;
    inset: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.82);
    backdrop-filter: blur(3px);
}
.ouv-carte {
    width: min(78vw, 900px);
    max-height: 86vh;
    display: flex;
    flex-direction: column;
    border: var(--line);
    border-radius: var(--r-md);
    overflow: hidden;
    box-shadow: var(--sh-1);
    background: var(--parch-900, #14100c);
}
.ouv-img {
    width: 100%;
    height: min(46vh, 440px);
    object-fit: cover;
    flex: none;
}
/* Pas d'image encore générée : un aplat plutôt qu'un cadre vide et cassé. */
.ouv-img-vide {
    background: linear-gradient(160deg, #241c14, #0d0a07);
}
.ouv-corps {
    padding: 20px 28px 26px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
}
.ouv-titre {
    margin: 0;
    font-family: var(--font-ui);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gold, #c9a24a);
}
.ouv-texte {
    margin: 0;
    font-family: var(--font-narr);
    font-style: italic;
    font-size: 21px;
    line-height: 1.5;
    color: var(--parch-100, #e8dcc6);
}
.ouv-etape {
    margin: 0;
    font-family: var(--font-narr);
    font-style: italic;
    font-size: 19px;
    color: var(--parch-100, #e8dcc6);
}
/* Jauge d'étapes : des segments, pas un pourcentage — la durée de chaque
   étape varie trop (une image ~70 s, la voix parfois zéro) pour qu'un
   pourcentage veuille dire quoi que ce soit. */
.ouv-jauge {
    display: flex;
    gap: 6px;
}
.ouv-jauge i {
    height: 4px;
    flex: 1;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.14);
    transition: background 0.4s ease;
}
.ouv-jauge i.fait {
    background: var(--gold, #c9a24a);
}
</style>

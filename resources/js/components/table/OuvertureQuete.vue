<script setup>
/**
 * Ouverture de quête plein cadre, sur l'écran de table.
 *
 * L'illustration de scène en grand, avec le texte qui plante le donjon —
 * l'image existait déjà mais n'apparaissait que dans une vignette de 56 px au
 * coin du bandeau.
 *
 * ⚠ Ce panneau portait AUSSI la préparation (habillage, scène, récits, voix),
 * et c'est parti le 2026-09-05. La quête est jouable dès sa création : la
 * cérémonie scriptée est lue en quelques secondes, les manettes dégèlent, et
 * les joueurs agissent — pendant que ce voile plein écran couvrait encore le
 * donjon une à deux minutes durant (René : « on est capable de jouer alors
 * qu'il y a un popup »). Il datait d'avant la bascule « zéro appel LLM en
 * quête » du 2026-08-18, quand le groupe attendait pour de bon. L'avancement
 * vit désormais dans un bandeau du haut, qui ne masque rien.
 *
 * Se ferme quand le narrateur a fini de lire, jamais toute seule : c'est la
 * table qui donne le tempo.
 */
defineProps({
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

                <p class="ouv-texte">{{ texte }}</p>
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
</style>

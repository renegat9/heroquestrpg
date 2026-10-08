<script setup>
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

defineProps({
    /** TABLE_PARTY : [{ l, c, ic, img?, body: [cur,max], mind: [cur,max], conds, acting?, low? }] */
    party: { type: Array, required: true },
});

const condIcon = { poison: 'coronavirus', burn: 'local_fire_department', buff: 'shield_with_heart' };
</script>

<template>
    <div class="group">
        <h2><MSym n="groups" fill /> Le groupe</h2>
        <div
            v-for="p in party"
            :key="p.l"
            class="hcard"
            :class="{ acting: p.acting, downed: p.body[0] === 0 }"
        >
            <div class="hh">
                <span class="crest"><Vignette :src="p.img" :icon="p.ic" fill /></span>
                <div>
                    <div class="hn">{{ p.l }}</div>
                    <div class="hc">{{ p.c }}</div>
                </div>
                <div class="conds">
                    <span
                        v-for="(c, i) in p.conds"
                        :key="i"
                        class="mini-badge"
                        :class="c.t ? `b-${c.t}` : null"
                        :title="c.l ? (c.d != null ? `${c.l} — ${c.d} tour${c.d > 1 ? 's' : ''}` : c.l) : null"
                    >
                        <MSym :n="c.ic || c.i || condIcon[c.t]" fill />
                        <i v-if="c.d != null" class="d">{{ c.d }}</i>
                    </span>
                </div>
            </div>
            <div class="pv-line">
                <span class="lab" style="color: var(--body-bright)">BODY</span>
                <div class="pips">
                    <div v-for="i in p.body[1]" :key="i" class="pip" :class="{ b: i <= p.body[0] }" />
                </div>
                <span class="num">{{ p.body[0] }}/{{ p.body[1] }}</span>
            </div>
            <div class="pv-line">
                <span class="lab" style="color: var(--mind-bright)">MIND</span>
                <div class="pips">
                    <div v-for="i in p.mind[1]" :key="i" class="pip" :class="{ m: i <= p.mind[0] }" />
                </div>
                <span class="num">{{ p.mind[0] }}/{{ p.mind[1] }}</span>
            </div>
            <!-- Faveurs de Hopekins Rest : nom + effet, tels que publiés par le serveur. -->
            <div v-if="p.faveurs?.length" class="grp-faveurs">
                <div v-for="f in p.faveurs" :key="f.cle" class="grp-faveur" :title="f.effet">
                    <MSym n="workspace_premium" fill :size="13" />
                    <b>{{ f.libelle }}</b>
                    <span>{{ f.effet }}</span>
                </div>
            </div>
            <div v-if="p.low" class="downed-tag">
                <MSym n="warning" fill /> État critique — à protéger
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Faveurs de Hopekins Rest sur la carte du héros (scopé : les blocs <style>
   globaux du projet font fuir les noms de classe). */
.grp-faveurs { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; }
.grp-faveur { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; line-height: 1.35;
  color: var(--ink-300, #cfc6b0); }
.grp-faveur > .msym { flex: none; margin-top: 1px; color: var(--torch, #c9a25a); }
.grp-faveur b { color: var(--parch-100, #f0e9d8); font-weight: 700; }
</style>

<script setup>
import MSym from '../ui/MSym.vue';

defineProps({
    /** TABLE_PARTY : [{ l, c, ic, body: [cur,max], mind: [cur,max], conds, acting?, low? }] */
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
                <span class="crest"><MSym :n="p.ic" fill /></span>
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
            <div v-if="p.low" class="downed-tag">
                <MSym n="warning" fill /> Gravement blessée — à protéger
            </div>
        </div>
    </div>
</template>

/* =========================================================================
   Données de démonstration — reprises telles quelles des maquettes
   reference/heroquest/ (manette-app.jsx, Table.html, index.html).
   À remplacer par l'API (useApi) + le temps réel (useEcho).
   ========================================================================= */

/* ------------------------------------------------------------- manette */
export const HEROES = {
    mage: {
        key: 'mage', name: 'Eldra Sombrelune', cls: 'Magicienne', crest: 'auto_awesome', icon: 'auto_fix_high',
        lvl: 3, body: { cur: 3, max: 4 }, mind: { cur: 5, max: 6 }, atkAttr: 2, mindAttr: 5, atk: 1, def: 2, hasSpells: true,
        conds: [{ t: 'buff', l: 'Renforcé', d: 2 }],
        gear: { arme: 'Bâton de sorcier', armure: 'Robe tissée', sac: 'Sacoche runique', conso: '2 fioles' },
    },
    barb: {
        key: 'barb', name: 'Gorrim Tuevague', cls: 'Barbare', crest: 'swords', icon: 'sports_martial_arts',
        lvl: 3, body: { cur: 5, max: 8 }, mind: { cur: 2, max: 2 }, atkAttr: 5, mindAttr: 2, atk: 3, def: 2, hasSpells: false,
        conds: [{ t: 'burn', l: 'Brûlure', d: 1 }],
        gear: { arme: 'Épée large', armure: 'Cotte de mailles', sac: 'Sac de cuir', conso: '1 potion' },
    },
    dwarf: {
        key: 'dwarf', name: 'Durik Forgefer', cls: 'Nain', crest: 'hardware', icon: 'construction',
        lvl: 3, body: { cur: 7, max: 7 }, mind: { cur: 3, max: 3 }, atkAttr: 4, mindAttr: 3, atk: 2, def: 2, hasSpells: false, isSmith: true,
        conds: [],
        gear: { arme: 'Hache naine', armure: 'Plates gravées', sac: 'Outils de forge', conso: '—' },
    },
    elf: {
        key: 'elf', name: 'Sylanwë', cls: 'Elfe', crest: 'park', icon: 'forest',
        lvl: 3, body: { cur: 6, max: 6 }, mind: { cur: 4, max: 4 }, atkAttr: 4, mindAttr: 4, atk: 2, def: 2, hasSpells: true,
        conds: [{ t: 'poison', l: 'Poison', d: 2 }],
        gear: { arme: 'Arc long', armure: 'Cuir clouté', sac: 'Carquois', conso: '3 flèches+' },
    },
};

export const SPELLS = [
    { id: 'fb', el: 'fire', name: 'Boule de feu', desc: '2 dés · cible à vue', target: 'foe', icon: 'local_fire_department' },
    { id: 'wall', el: 'fire', name: 'Mur de flammes', desc: 'Bloque une porte · 3t', target: 'tile', icon: 'fireplace' },
    { id: 'heal', el: 'water', name: 'Source de vie', desc: '+2 Body · allié', target: 'ally', icon: 'water_drop' },
    { id: 'ice', el: 'water', name: 'Éclat de givre', desc: '1 dé + Ralenti', target: 'foe', icon: 'ac_unit' },
    { id: 'rock', el: 'earth', name: 'Peau de roc', desc: '+2 Défense · allié · 2t', target: 'ally', icon: 'landscape' },
    { id: 'gust', el: 'air', name: 'Bourrasque', desc: 'Repousse · 2 cases', target: 'foe', icon: 'air' },
];
export const EL_LABEL = { fire: 'Feu', water: 'Eau', earth: 'Terre', air: 'Air' };
export const EL_CLASS = { fire: 'el-fire', water: 'el-water', earth: 'el-earth', air: 'el-air' };

export const FOES = [
    { id: 'g1', name: 'Gobelin', icon: 'sentiment_very_dissatisfied', body: 1, dist: 'à portée' },
    { id: 'g2', name: 'Gobelin', icon: 'sentiment_very_dissatisfied', body: 1, dist: 'à portée' },
    { id: 'orc', name: 'Orc capitaine', icon: 'crew', body: 3, dist: '2 cases' },
];

export const ALLIES = [
    { id: 'bar', name: 'Gorrim (Barbare)', icon: 'swords' },
    { id: 'elf', name: 'Sylanwë (Elfe)', icon: 'park' },
];

export const BACKPACK = [
    { name: 'Potion de soin', qty: 2, rar: 'common', icon: 'science', price: 50 },
    { name: 'Parchemin de Téléport', qty: 1, rar: 'rare', icon: 'description', price: 0 },
    { name: 'Torche', qty: 3, rar: 'common', icon: 'local_fire_department', price: 0 },
];

export const SHOP = [
    { id: 'pot', name: 'Potion de soin', rar: 'common', icon: 'science', price: 50 },
    { id: 'scr', name: 'Parchemin de feu', rar: 'uncommon', icon: 'description', price: 120 },
    { id: 'dag', name: 'Dague enchantée', rar: 'uncommon', icon: 'colorize', price: 200 },
    { id: 'arm', name: 'Armure de cuir cloutée', rar: 'rare', icon: 'shield', price: 180 },
    { id: 'amu', name: 'Amulette du Spectre', rar: 'unique', icon: 'diamond', price: 900 },
];
export const RAR_LABEL = { common: 'Commun', uncommon: 'Peu commun', rare: 'Rare', unique: 'Unique' };

export const FORGE_CAT = [
    { id: 'f1', name: 'Aiguiser la lame', desc: "+1 dé d'attaque", price: 160 },
    { id: 'f2', name: "Renforcer l'armure", desc: '+1 dé de défense', price: 200 },
    { id: 'f3', name: 'Gravure runique', desc: 'Ignore 1 bouclier ennemi', price: 340 },
];

export const INIT_ORDER_MINI = [
    { k: 'MAG', foe: false },
    { k: 'BAR', foe: false },
    { k: 'G1', foe: true },
    { k: 'ELF', foe: false },
    { k: 'G2', foe: true },
];

/* ------------------------------------------------------------- table */
export const TABLE_PARTY = [
    { l: 'Sylanwë', c: 'Elfe', ic: 'park', body: [6, 6], mind: [4, 4], conds: [{ t: 'poison', i: 'coronavirus' }], acting: true },
    { l: 'Gorrim Tuevague', c: 'Barbare', ic: 'sports_martial_arts', body: [5, 8], mind: [2, 2], conds: [{ t: 'burn', i: 'local_fire_department' }] },
    { l: 'Durik Forgefer', c: 'Nain', ic: 'construction', body: [7, 7], mind: [3, 3], conds: [] },
    { l: 'Eldra Sombrelune', c: 'Magicienne', ic: 'auto_fix_high', body: [1, 4], mind: [4, 6], conds: [{ t: 'buff', i: 'shield_with_heart' }], low: true },
];

export const TABLE_INIT_ORDER = [
    { l: 'ELF', cur: true },
    { l: 'NAI' },
    { l: 'BAR' },
    { l: 'G1', foe: true },
    { l: 'MAG' },
    { l: 'G2', foe: true },
];

export const TABLE_ENTITIES = [
    { x: 1, y: 1, k: 'hero', l: 'BAR', ic: 'sports_martial_arts' },
    { x: 2, y: 6, k: 'hero', l: 'NAI', ic: 'construction' },
    { x: 3, y: 6, k: 'hero', l: 'MAG', ic: 'auto_fix_high' },
    { x: 7, y: 1, k: 'hero', l: 'ELF', ic: 'park', cur: true },
    { x: 9, y: 1, k: 'foe', l: 'G1', ic: 'sentiment_very_dissatisfied', hp: 1, tgt: true },
    { x: 8, y: 6, k: 'foe', l: 'G2', ic: 'sentiment_very_dissatisfied', hp: 1 },
    { x: 10, y: 6, k: 'foe', l: 'ORC', ic: 'crew', hp: 3 },
    { x: 4, y: 1, k: 'chest', ic: 'inventory_2' },
];

/** Pièges visibles (contrat carte.pieges — les cachés n'arrivent jamais). */
export const TABLE_TRAPS = [
    { x: 9, y: 2, etat: 'detecte', nom: 'Fosse', titre: 'Fosse — détectée' },
    { x: 2, y: 5, etat: 'desarme', nom: 'Piège à lances', titre: 'Piège à lances — désarmé' },
    { x: 8, y: 5, etat: 'declenche', nom: 'Chute de blocs', titre: 'Chute de blocs — déclenchée' },
];

/** Construit le terrain de la maquette Table.html (14 × 9). */
export function buildTableMap() {
    const C = 14;
    const R = 9;
    const g = Array.from({ length: R }, () => Array.from({ length: C }, () => 'void'));
    const fill = (x, y, w, h, t) => {
        for (let j = y; j < y + h; j++)
            for (let i = x; i < x + w; i++)
                if (g[j] && g[j][i] !== undefined) g[j][i] = t;
    };
    const room = (x, y, w, h) => {
        fill(x, y, w, h, 'wall');
        fill(x + 1, y + 1, w - 2, h - 2, 'floor');
    };

    room(0, 0, 6, 5); // Salle A (haut-gauche)
    room(6, 0, 6, 5); // Salle C (haut-centre)
    room(0, 4, 8, 5); // Hall (bas-gauche)
    room(7, 4, 7, 5); // Salle D (bas-droite)
    // couloirs / portes
    g[2][5] = 'door'; g[2][6] = 'floor'; // A <-> C
    g[4][2] = 'door'; // A <-> Hall
    g[4][9] = 'door'; // C <-> D
    g[5][7] = 'floor'; g[6][7] = 'floor'; // hall <-> D
    g[2][8] = 'floor';
    // salle brumeuse (inexplorée)
    fill(11, 1, 3, 3, 'fog'); g[2][11] = 'floor'; g[2][10] = 'door';
    // murs intérieurs (texture)
    g[6][4] = 'wall'; g[5][4] = 'wall';

    // portée de déplacement du héros courant (ELF en 7,1)
    const range = [[8, 1], [8, 2], [7, 2], [9, 2], [6, 2], [6, 3]];
    const inRange = (x, y) => range.some((c) => c[0] === x && c[1] === y);

    const cells = [];
    for (let y = 0; y < R; y++)
        for (let x = 0; x < C; x++)
            cells.push({ x, y, t: g[y][x], range: inRange(x, y) && (g[y][x] === 'floor' || g[y][x] === 'door') });

    return { C, R, cells };
}

/* ------------------------------------------------------------- hub */
export const ROSTER = [
    { n: 'Gorrim Tuevague', c: 'Barbare', ic: 'sports_martial_arts', lvl: 3, gold: 520 },
    { n: 'Eldra Sombrelune', c: 'Magicienne', ic: 'auto_fix_high', lvl: 3, gold: 610 },
    { n: 'Durik Forgefer', c: 'Nain', ic: 'construction', lvl: 3, gold: 740 },
    { n: 'Sylanwë', c: 'Elfe', ic: 'park', lvl: 3, gold: 610 },
];

/* ------------------------------------------------- manette (narration) */
export const NARRATION_OUVERTURE =
    "Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se redressent en grognant…";

/* ------------------------------------------------- sélection de quête */
export const QUEST_NODES = {
    q1: { x: 10, y: 30, state: 'done', ic: 'door_front', label: 'Le Porche' },
    q2: { x: 26, y: 62, state: 'done', ic: 'water_drop', label: 'Les Égouts' },
    q3: { x: 42, y: 38, state: 'current', ic: 'castle', label: 'La Crypte' },
    qa: { x: 66, y: 20, state: 'avail', ic: 'local_fire_department', label: 'La Forge Maudite' },
    qb: { x: 66, y: 54, state: 'avail', ic: 'sentiment_very_dissatisfied', label: 'Le Nid Gobelin' },
    qc: { x: 66, y: 84, state: 'avail', ic: 'water', label: 'Les Catacombes Noyées' },
    qf: { x: 90, y: 50, state: 'locked', ic: 'lock', label: '???' },
};
export const QUEST_EDGES = [
    ['q1', 'q2'], ['q2', 'q3'], ['q3', 'qa'], ['q3', 'qb'], ['q3', 'qc'],
    ['qa', 'qf'], ['qb', 'qf'], ['qc', 'qf'],
];
export const QUESTS = {
    qa: {
        name: 'La Forge Maudite', diff: 3, dl: 'Périlleuse', lvl: 'Niv. 3-4', dur: '~50 min', el: 'Feu',
        hook: "Une forge naine, jadis glorieuse, crache désormais une chaleur qui n'a rien de naturel. Les enclumes frappent seules dans le noir.",
        rewards: [['gold', '450 or'], ['item', 'Marteau runique']],
    },
    qb: {
        name: 'Le Nid Gobelin', diff: 2, dl: 'Risquée', lvl: 'Niv. 3', dur: '~35 min', el: 'Terre',
        hook: "Les gobelins se sont multipliés dans les galeries est. Leur chef, une brute balafrée, garde un butin volé aux marchands.",
        rewards: [['gold', '280 or'], ['item', "Bottes de l'éclaireur"]],
    },
    qc: {
        name: 'Les Catacombes Noyées', diff: 3, dl: 'Périlleuse', lvl: 'Niv. 4', dur: '~55 min', el: 'Eau',
        hook: "L'eau monte dans les galeries inférieures, et quelque chose d'ancien remue sous la surface. Le silence y est plus dangereux que le bruit.",
        rewards: [['gold', '520 or'], ['item', 'Amulette du Spectre']],
    },
};
export const QUEST_PLAYERS = [
    { k: 'barb', ic: 'sports_martial_arts', n: 'Bar' },
    { k: 'mage', ic: 'auto_fix_high', n: 'Mag' },
    { k: 'dwarf', ic: 'construction', n: 'Nai' },
    { k: 'elf', ic: 'park', n: 'Elf' },
];

/* ------------------------------------------------- montée de niveau */
export const LEVELUP_HERO = {
    name: 'Eldra Sombrelune', cls: 'Magicienne', icon: 'auto_fix_high', from: 3, to: 4,
    done: "Eldra grave une nouvelle rune dans son grimoire. Sa puissance s'épanouit.",
};
export const LEVELUP_GAINS = [
    { kind: 'mind', ic: 'psychology', t: 'Esprit aiguisé', d: 'Réserve de Mind maximale', from: 6, to: 7 },
    { kind: 'body', ic: 'favorite', t: 'Endurance', d: 'Réserve de Body maximale', from: 4, to: 5 },
];
export const LEVELUP_TALENTS = [
    { k: 't1', ic: 'local_fire_department', t: 'Maîtrise du Feu', d: "Tes sorts de Feu lancent 1 dé d'attaque supplémentaire.", el: 'fire', elt: 'Élément Feu', eli: 'bolt' },
    { k: 't2', ic: 'menu_book', t: 'Nouveau sort — Givre noyé', d: "Apprends un sort d'Eau : gèle un ennemi sur place pour 1 tour.", el: 'water', elt: 'Nouveau sort', eli: 'ac_unit' },
    { k: 't3', ic: 'shield_with_heart', t: 'Ward arcanique', d: 'Une fois par quête, annule entièrement une attaque qui te cible.' },
];

/* ------------------------------------------------- clôture de campagne */
export const CLOTURE_HEROES = [
    { k: 'barb', ic: 'sports_martial_arts', n: 'Bar' },
    { k: 'mage', ic: 'auto_fix_high', n: 'Mag' },
    { k: 'dwarf', ic: 'construction', n: 'Nai' },
    { k: 'elf', ic: 'park', n: 'Elf' },
];
export const CLOTURE_REWARDS = [
    { n: 'Lame du Spectre', rar: 'unique', ic: 'colorize', to: 'barb' },
    { n: 'Grimoire Noyé', rar: 'rare', ic: 'auto_stories', to: 'mage' },
    { n: 'Heaume runique', rar: 'rare', ic: 'sports_motorsports', to: 'dwarf' },
    { n: 'Carquois sans fin', rar: 'uncommon', ic: 'arrow_outward', to: 'elf' },
    { n: "Amulette d'Ambre", rar: 'unique', ic: 'diamond', to: 'mage' },
    { n: 'Potion de soin majeure', rar: 'uncommon', ic: 'science', to: 'barb' },
];

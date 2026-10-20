---
name: medias-images-et-sons
description: >-
  Utiliser pour tout ce qui touche aux MÉDIAS générés : illustrations du
  catalogue et des scènes, vignettes de repli, voix du narrateur, répliques de
  monstres (barks), musique d'ambiance. Couvre la génération, les quotas, les
  jumeaux webp et les replis sans clé API. Déclencheurs : « génère les images »,
  « l'illustration manque », « la vignette », « une voix / le narrateur parle »,
  « les barks », « la musique d'ambiance », « le quota TTS », « webp », « le
  placeholder ».
---

# Médias — illustrations, voix, ambiance

**Deux règles avant tout le reste.**
1. **Tout doit rester jouable SANS clé API** : icône de repli, texte lu en Web
   Speech, emblème SVG. Ce n'est pas un mode dégradé, c'est une façon de jouer
   supportée.
2. **Chaque PNG généré a besoin de son jumeau `.webp`.** Sans lui rien ne casse —
   l'écran devient simplement **trente fois plus lourd** (le marché est monté à
   ~46 Mo sur un téléphone). PNG ~1,3 Mo, webp ~50 Ko.

## Images

```bash
docker compose exec app php artisan images:generer --type=tous   # résumable
#   --type=classes|monstres|mercenaires|objets|pieges|epreuves|mobiliers|terrains|leviers|portes|sorts|tous
#   --force  pour rejouer
./image-tools/webp.sh          # ⚠ TOUJOURS après images:generer
docker compose exec app php artisan images:purger-orphelines
```

- Gabarits de prompt : `config/images.php`. La commande **itère le catalogue**,
  donc une ligne neuve est couverte toute seule — sauf **type** inédit, qui
  demande gabarit + accesseur `BibliothequeImages::url*()` + itération dans
  `GenererImages` + champ dans le payload + rendu `Vignette`.
- ⚠ **Les éléments sans table catalogue** (levier, porte) sont nommés par un
  **libellé fixe** (`catalogue/leviers/levier.png`, `catalogue/portes/{etat}.png`),
  pas par `{id}-{slug}` : il n'y a pas de ligne à numéroter. Et **une image par
  ÉTAT de porte** — une porte barrée et un passage secret ne doivent pas être
  indiscernables ; un test confronte `config('images.portes')` aux `MoteurPortes::ETAT_*`
  **dans les deux sens**.
- ⚠ **Le jumeau webp naît à l'écriture depuis le 2026-09-14** : l'image `app`
  embarque GD compilé avec webp, et `BibliothequeImages::enregistrer()` — point de
  passage unique de toute image générée — appelle `ConvertisseurWebp::jumeler()`.
  `image-tools/webp.sh` reste le rattrapage des PNG plus anciens ou déposés à la main.
- ⚠ **Un inventaire par `url*() === null` ne compte rien** : `urlMonstreCatalogue()`
  et consorts rendent déjà l'emblème de repli. Chercher `/placeholder/` dans l'URL.
- **Repli** : `PlaceholderController` rend un **emblème SVG** par type de sujet
  (`GET /api/placeholder/{type}/{graine}`), stable et distinct par graine.
  ⚠ L'emblème est **en FIN de chaîne, jamais au milieu** : un portrait de héros
  passe d'abord par l'illustration de sa classe, un boss par celle de son
  archétype. Une vraie image, même générique, bat un emblème.
  ⚠ `urlDyn()` renvoie **`null`** exprès (accesseur brut) ; seuls les accesseurs
  publics sont totaux. Et **pas de sujet = pas de vignette** : `urlClasse(null)`
  reste `null`, un emblème prétendrait « image manquante » là où aucune n'était
  attendue.
- ⚠ **Aucune génération d'image côté joueur.** Les portraits à la demande ont été
  retirés ; la génération est narrateur-side.

## Sons

```bash
docker compose exec app php artisan barks:generer      # répliques de monstres
docker compose exec app php artisan narration:generer  # voix du narrateur
node audio-tools/lyria-ambiance.mjs                    # boucles d'ambiance
```

- **Barks** : texte dans `config/barks.php` (profil de voix par archétype,
  `lignes` attaque/touché/raté/mort, `lignes_boss` avec `{nom}`). Résolveur
  `BanqueBarks`. Sans clé ni asset, la table **lit le texte** en Web Speech —
  l'audio est de l'ambiance, jamais une mécanique.
  ⚠ **Tout monstre ajouté au bestiaire doit recevoir sa voix** dans
  `barks.archetypes` — `BarksArchetypesTest` échoue sinon (registre testé dans les
  deux sens, 2026-10-20 : 63 créatures d'extension retombaient en silence sur
  `defaut`). Une voix par **famille** (ogre, bete, sorcier, fleau…), pas par
  monstre : un profil neuf coûte 12 requêtes TTS.
- **Narration** : `config/narration.php` (`lancement`, `repli`). ⚠ Le repli
  scripté est le **cas nominal**, pas un filet : il porte 21 des 24 temps forts
  et joue pendant les premières secondes de **chaque** quête.
  ⚠ **Une ligne à variables (`{heros}`, `{or}`…) n'a JAMAIS d'audio** : elle
  n'existe qu'une fois substituée. `BibliothequeNarration::aDesVariables()` est
  le seul point de passage — `narration:generer` la saute, `repli()` ne sert pas
  son fichier (avant le 2026-10-20, 73 lignes étaient synthétisées accolades
  comprises et jouées à la table). Une variante **sans** variable coûte 1 requête
  TTS ; une variante **à** variables est gratuite — et les clés fréquentes en
  veulent 8 à 12, pas 2.
- ⚠ **Un fichier pré-généré est lié à son TEXTE, pas à son rang** : chaque
  `{i}.wav` porte `{i}.sha1` (`App\Partie\Audio\BanqueAudio`, narration ET
  barks). Retoucher une réplique dans `config/` suffit : la commande régénère ce
  qui a changé, supprime ce qui ne correspond plus à rien, et la table ne joue
  jamais un fichier dont l'empreinte diffère du texte. **Plus de `rm -r` à la
  main.** Un fichier sans empreinte n'est jamais joué ; le premier passage l'adopte.
- ⚠ **Quota Gemini TTS : 100 requêtes/jour**, même facturé. Les commandes sont
  résumables (elles sautent l'existant) ; étaler sur plusieurs jours.
- **Ambiance** : `public/audio/ambiance/{scene}.{ogg|mp3|wav}`, scène dérivée
  dans `EtatGroupe.groupe.ambiance`. Générée par **Lyria** (pas du TTS) — voir
  `audio-tools/README.md`.
- Tout `public/audio/**` et les PNG générés sont **gitignorés** (régénérables).

## Lore
`docs/regles/medias-images-et-audio.md` · `narration-et-ia.md`

## Definition of done
- [ ] `images:generer` **puis** `image-tools/webp.sh`
- [ ] Type inédit : gabarit + accesseur + itération + payload + `Vignette`
- [ ] Repli vérifié **sans clé API** (icône / emblème / Web Speech)
- [ ] Quota TTS respecté, génération résumable
- [ ] Orphelines purgées si des lignes de catalogue ont disparu

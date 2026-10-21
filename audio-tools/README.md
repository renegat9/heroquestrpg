# audio-tools — génération audio

Outils de génération des assets audio (hors dépôt : les fichiers produits sont
gitignorés sous `public/audio/`). Tout est **best-effort** : sans clé, le jeu
reste jouable (voix navigateur Web Speech, pas de musique).

## Voix (barks de monstres, narrateur) — Gemini TTS

Via artisan (clé `GEMINI_API_KEY` dans `.env`) :

```bash
docker compose exec app php artisan barks:generer        # cris des monstres
docker compose exec app php artisan narration:generer    # voix de narrateur (répliques scriptées)
```

⚠ Le modèle preview `gemini-2.5-flash-tts` plafonne à **100 requêtes/jour** même
avec facturation. Les commandes sont reprenables (sautent l'existant).

## Musique d'ambiance — Lyria RealTime

`lyria-ambiance.mjs` génère, via le modèle **Lyria RealTime** de Google
(musique instrumentale en streaming, PAS du TTS — hors quota des 100/jour),
**six scènes** — `hub`, `exploration`, `combat`, `boss`, `victoire`,
`defaite` — en `VARIANTS` boucles chacune (défaut 2), WAV 48 kHz stéréo dans
`public/audio/ambiance/{scene}/{n}.wav`, plus `manifeste.json` qui les liste.
La table tire une variante au hasard et enchaîne en fondu selon
`groupe.ambiance`, décidée par le serveur (`EtatGroupe::sceneAmbiance()`).

```bash
# depuis la racine du dépôt, GEMINI_API_KEY pris du .env
KEY=$(grep '^GEMINI_API_KEY=' .env | cut -d= -f2-)
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e GEMINI_API_KEY="$KEY" -e CAPTURE_MS=40000 node:20-alpine \
  sh -c "npm i @google/genai --no-save --silent && node audio-tools/lyria-ambiance.mjs"
```

`CAPTURE_MS` = durée capturée par variante (défaut 40 s), `VARIANTS` = nombre
de boucles par scène. Édite les `SCENES` (prompt, teintes, bpm) dans le script
pour changer l'ambiance ; les prompts sont en anglais (meilleur rendu Lyria).

⚠ **Ce script n'est PAS reprenable**, contrairement à `barks:generer` et
`narration:generer` : chaque lancement régénère TOUTES les scènes et réécrit
`manifeste.json` — une variante en échec en disparaît, même si un ancien
fichier existait. Il ne sait pas encore refaire une seule scène : réduire
`SCENES` réécrirait le manifeste SANS les autres, et la table les jouerait en
silence.

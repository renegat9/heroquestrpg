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

`lyria-ambiance.mjs` génère une boucle par scène sonore
(`hub`, `exploration`, `combat`, `boss`) via le modèle **Lyria RealTime** de
Google (musique instrumentale en streaming), écrite en WAV 48 kHz stéréo dans
`public/audio/ambiance/{scene}.wav`. Le lecteur de la table les enchaîne en
fondu selon `groupe.ambiance` (cf. EtatGroupe).

```bash
# depuis la racine du dépôt, GEMINI_API_KEY pris du .env
KEY=$(grep '^GEMINI_API_KEY=' .env | cut -d= -f2-)
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e GEMINI_API_KEY="$KEY" -e CAPTURE_MS=40000 node:20-alpine \
  sh -c "npm i @google/genai --no-save --silent && node audio-tools/lyria-ambiance.mjs"
```

`CAPTURE_MS` = durée capturée par scène (défaut 40 s). Édite les `SCENES`
(prompt/bpm) dans le script pour changer l'ambiance. Les prompts sont en
anglais (meilleur rendu Lyria).

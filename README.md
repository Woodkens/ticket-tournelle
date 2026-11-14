# Ticket'Tournelle — README technique

Portail de déclaration et de suivi d'incidents techniques du lycée La Tournelle (BTS SIO 2 SLAM).
Binôme : William Matondo Ngongo · Woodkens Guervil.

Ce fichier liste **chaque commande d'installation**, dans l'ordre, pour que chaque membre du binôme
puisse refaire le même poste et pour préparer le serveur Debian 13 en S5.
Documents de référence : `docs/` (cahier des charges, R1, R2, MPD, journal de décisions).

> Règle absolue : aucun secret dans ce fichier ni dans Git. Les mots de passe vivent uniquement
> dans `.env.local` (ignoré par Git), générés sur le poste.

---

## A. Dépôt Git

### A.1 Création (déjà faite, pour mémoire)

```bash
git init -b main
git add .gitignore .gitattributes README.md CLAUDE.md docs .claude/skills
git commit -m "chore(depot): initialisation du dépôt et de la documentation"
```

- `.gitignore` est présent **dès le premier commit** : `.env.local`, `/vendor`, `/var`, pièces jointes… (ENF-11).
- `.gitattributes` force les fins de ligne Unix (LF) : sans cela, Windows ajoute des `CR` qui cassent
  les scripts shell et la configuration Nginx sur Debian.

### A.2 Dépôt distant GitHub (privé)

Créé sur github.com : dépôt **privé** `ticket-tournelle`, sans README ni licence (le dépôt local en a déjà).

```bash
git remote add origin https://github.com/<compte>/ticket-tournelle.git
git push -u origin main
```

### A.3 Relecture croisée (ENF-32)

- On ne travaille jamais directement sur `main` : une branche `feature/<sujet>` par fonctionnalité.
- Fusion dans `main` uniquement par **Pull Request relue par l'autre membre du binôme**.
- Si GitHub permet la protection de branche sur le compte (dépôt privé), elle est activée :
  PR obligatoire avant fusion. Sinon, la règle s'applique **par convention** (voir section « État »).

### A.4 Convention de commits

Format `type(portée): description` en français. Types : `feat`, `fix`, `sec`, `test`, `docs`, `chore`.

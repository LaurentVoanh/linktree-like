# TikLinks ⚡

> Clone de Linktree optimisé TikTok — PHP natif, SQLite, zéro dépendance.

## 🚀 Features

- ✅ **Auth complète** : inscription/connexion avec `password_hash()`
- ✅ **Profil personnalisable** : bio, avatar, handle TikTok, nom affiché
- ✅ **Liens illimités** : titre, URL, icône emoji, ordre, activation/désactivation
- ✅ **Embed vidéos TikTok** : intégration native via iframe
- ✅ **5 thèmes visuels** : CyberPunk ⚡, Punk 🤘, Artiste 🎨, Vaporwave 🌸, Minimaliste ◾
- ✅ **Admin dashboard** : gestion en temps réel, stats, preview live
- ✅ **Responsive** : mobile-first, animations CSS, effets cyberpunk
- ✅ **Lightweight** : PHP 8+ SQLite WAL, pas de cURL, pas de composer

## 📦 Requirements

```bash
PHP >= 8.0
SQLite3 (activé par défaut)
Serveur web (Apache/Nginx) ou `php -S localhost:8000`
```

## ⚙️ Installation

```bash
# 1. Cloner ou déposer index.php dans votre dossier web
# 2. Assurer les droits d'écriture sur le dossier (pour linkdata.sqlite)
# 3. Accéder à http://votre-domaine/index.php

# Dev local rapide :
php -S localhost:8000 index.php
```

> La BDD `linkdata.sqlite` se crée automatiquement au premier accès.

## 🎯 Usage

### Page publique
```
https://votre-site/index.php?u=pseudo
```

### Admin
```
https://votre-site/index.php?action=admin
```

### Flux principal
| Route | Description |
|-------|-------------|
| `/` | Landing page |
| `?action=register` | Inscription |
| `?action=login` | Connexion |
| `?action=admin` | Dashboard (auth requis) |
| `?u={username}` | Page publique utilisateur |

## 🗂 Structure

```
index.php              # Application mono-fichier (MVC inline)
linkdata.sqlite       # Base SQLite (créée auto)
linkdata.sqlite-wal   # Write-Ahead Log (SQLite WAL mode)
```

## 🎨 Thèmes disponibles

```php
'cyberpunk'   // ⚡ Neon cyan/magenta, scanlines, font Orbitron
'punk'        // 🤘 Rouge agressif, noise, Bebas Neue
'artiste'     // 🎨 Or/ambre, grain, Cinzel serif
'vaporwave'   // 🌸 Rose/violet, grid retro, Pacifico
'minimaliste' // ◾ Noir/blanc épuré, DM Sans
```

## 🔧 Configuration avancée

```php
// Modifier SITE_URL si besoin (détection auto sinon)
define('SITE_URL', 'https://votre-domaine.com/index.php');

// Changer le path de la BDD
define('DB_PATH', __DIR__ . '/data/mabase.sqlite');
```

## 🔒 Sécurité

- Mots de passe hashés via `PASSWORD_DEFAULT` (bcrypt/argon2id)
- Requêtes préparées PDO (injection SQL protégée)
- Échappement HTML systématique (`htmlspecialchars`)
- Sessions PHP natives, régénération implicite

## 🌐 Embed TikTok

Format accepté :
```
https://www.tiktok.com/@pseudo/video/1234567890
```

L'iframe utilise l'endpoint officiel `tiktok.com/embed/v2/{video_id}`.

## 🛠 Dev & Contrib

```bash
# Lancer en local
php -S localhost:8000

# Tester la création BDD
curl http://localhost:8000/index.php?action=register

# Vérifier les logs SQLite
sqlite3 linkdata.sqlite ".tables"
```

## 📄 License

MIT — Fait avec ⚡ par la commu geek.

---

> 💡 **Astuce** : Ajoute `?u=tonpseudo` à ton bio TikTok pour rediriger vers ta page TikLinks !

```
┌─────────────────────────────┐
│  TikLinks v1.0              │
│  PHP 8+ • SQLite • Zero Dep │
│  ⚡ Deploy in 60 seconds    │
└─────────────────────────────┘
```

<?php
// ============================================================
// index.php - TikTok Linktree Clone
// Stack: PHP 8+, SQLite WAL, file_get_contents (no cURL)
// ============================================================

define('DB_PATH', __DIR__ . '/linkdata.sqlite');
define('SITE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['SCRIPT_NAME']);

// ─── DB INIT ────────────────────────────────────────────────
function getDB(): PDO {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("
        CREATE TABLE IF NOT EXISTS members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            tiktok_handle TEXT DEFAULT '',
            display_name TEXT DEFAULT '',
            bio TEXT DEFAULT '',
            avatar_url TEXT DEFAULT '',
            template TEXT DEFAULT 'cyberpunk',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            icon TEXT DEFAULT '🔗',
            sort_order INTEGER DEFAULT 0,
            active INTEGER DEFAULT 1,
            FOREIGN KEY(member_id) REFERENCES members(id)
        );
        CREATE TABLE IF NOT EXISTS tiktok_videos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            video_url TEXT NOT NULL,
            caption TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY(member_id) REFERENCES members(id)
        );
    ");
    return $db;
}

// ─── SESSION + AUTH ─────────────────────────────────────────
session_start();
$db = getDB();

function isLoggedIn(): bool { return isset($_SESSION['member_id']); }
function currentMember(): ?array {
    if (!isLoggedIn()) return null;
    global $db;
    $s = $db->prepare("SELECT * FROM members WHERE id=?");
    $s->execute([$_SESSION['member_id']]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─── ROUTING ────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$slug   = $_GET['u'] ?? '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $display  = trim($_POST['display_name'] ?? $username);
        if (strlen($username) >= 3 && strlen($password) >= 6) {
            try {
                $db->prepare("INSERT INTO members (username,password,display_name) VALUES (?,?,?)")
                   ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display]);
                $_SESSION['member_id'] = $db->lastInsertId();
                header('Location: ' . SITE_URL . '?action=admin');
                exit;
            } catch (Exception $e) {
                $error = "Nom d'utilisateur déjà pris.";
            }
        } else {
            $error = "Username 3+ chars, mot de passe 6+ chars.";
        }
    }

    if ($post_action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $s = $db->prepare("SELECT * FROM members WHERE username=?");
        $s->execute([$username]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if ($m && password_verify($password, $m['password'])) {
            $_SESSION['member_id'] = $m['id'];
            header('Location: ' . SITE_URL . '?action=admin');
            exit;
        } else {
            $error = "Identifiants incorrects.";
        }
    }

    if ($post_action === 'logout') {
        session_destroy();
        header('Location: ' . SITE_URL);
        exit;
    }

    if ($post_action === 'save_profile' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("UPDATE members SET display_name=?, bio=?, tiktok_handle=?, avatar_url=?, template=? WHERE id=?")
           ->execute([
               trim($_POST['display_name'] ?? $m['display_name']),
               trim($_POST['bio'] ?? ''),
               trim($_POST['tiktok_handle'] ?? ''),
               trim($_POST['avatar_url'] ?? ''),
               $_POST['template'] ?? 'cyberpunk',
               $m['id']
           ]);
        header('Location: ' . SITE_URL . '?action=admin&saved=1');
        exit;
    }

    if ($post_action === 'add_link' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("INSERT INTO links (member_id,title,url,icon,sort_order) VALUES (?,?,?,?,?)")
           ->execute([$m['id'], trim($_POST['title']), trim($_POST['url']), trim($_POST['icon'] ?? '🔗'),
                      (int)$_POST['sort_order']]);
        header('Location: ' . SITE_URL . '?action=admin&tab=links&saved=1');
        exit;
    }

    if ($post_action === 'delete_link' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("DELETE FROM links WHERE id=? AND member_id=?")->execute([(int)$_POST['link_id'], $m['id']]);
        header('Location: ' . SITE_URL . '?action=admin&tab=links');
        exit;
    }

    if ($post_action === 'toggle_link' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("UPDATE links SET active=1-active WHERE id=? AND member_id=?")->execute([(int)$_POST['link_id'], $m['id']]);
        header('Location: ' . SITE_URL . '?action=admin&tab=links');
        exit;
    }

    if ($post_action === 'add_video' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("INSERT INTO tiktok_videos (member_id,video_url,caption,sort_order) VALUES (?,?,?,?)")
           ->execute([$m['id'], trim($_POST['video_url']), trim($_POST['caption'] ?? ''), (int)$_POST['sort_order']]);
        header('Location: ' . SITE_URL . '?action=admin&tab=videos&saved=1');
        exit;
    }

    if ($post_action === 'delete_video' && isLoggedIn()) {
        $m = currentMember();
        $db->prepare("DELETE FROM tiktok_videos WHERE id=? AND member_id=?")->execute([(int)$_POST['video_id'], $m['id']]);
        header('Location: ' . SITE_URL . '?action=admin&tab=videos');
        exit;
    }
}

// ─── TEMPLATES CONFIG ───────────────────────────────────────
$templates = [
    'cyberpunk' => [
        'name'  => 'CyberPunk',
        'emoji' => '⚡',
        'bg'    => '#0a0a0f',
        'card'  => 'rgba(0,255,255,0.05)',
        'border'=> '#00ffff',
        'text'  => '#00ffff',
        'sub'   => '#ff00ff',
        'btn'   => 'linear-gradient(90deg,#00ffff,#ff00ff)',
        'btnTxt'=> '#000',
        'glow'  => '0 0 20px #00ffff55, 0 0 40px #ff00ff33',
        'font'  => "'Orbitron', monospace",
        'bodyFont' => "'Share Tech Mono', monospace",
        'extra' => 'scanlines',
    ],
    'punk' => [
        'name'  => 'Punk',
        'emoji' => '🤘',
        'bg'    => '#111',
        'card'  => 'rgba(255,20,20,0.08)',
        'border'=> '#ff1414',
        'text'  => '#fff',
        'sub'   => '#ff1414',
        'btn'   => 'linear-gradient(90deg,#ff1414,#ff6600)',
        'btnTxt'=> '#fff',
        'glow'  => '0 0 15px #ff141466',
        'font'  => "'Bebas Neue', sans-serif",
        'bodyFont' => "'Barlow Condensed', sans-serif",
        'extra' => 'noise',
    ],
    'artiste' => [
        'name'  => 'Artiste',
        'emoji' => '🎨',
        'bg'    => '#1a1008',
        'card'  => 'rgba(255,200,80,0.07)',
        'border'=> '#d4a017',
        'text'  => '#f0d080',
        'sub'   => '#c8860a',
        'btn'   => 'linear-gradient(135deg,#d4a017,#8b4513)',
        'btnTxt'=> '#fff',
        'glow'  => '0 0 20px #d4a01744',
        'font'  => "'Cinzel', serif",
        'bodyFont' => "'Crimson Text', serif",
        'extra' => 'grain',
    ],
    'vaporwave' => [
        'name'  => 'Vaporwave',
        'emoji' => '🌸',
        'bg'    => '#1a0933',
        'card'  => 'rgba(255,120,220,0.08)',
        'border'=> '#ff78dc',
        'text'  => '#ffe4f7',
        'sub'   => '#b4a0ff',
        'btn'   => 'linear-gradient(90deg,#ff78dc,#b4a0ff)',
        'btnTxt'=> '#1a0933',
        'glow'  => '0 0 25px #ff78dc55, 0 0 50px #b4a0ff33',
        'font'  => "'Pacifico', cursive",
        'bodyFont' => "'Quicksand', sans-serif",
        'extra' => 'grid',
    ],
    'minimaliste' => [
        'name'  => 'Minimaliste',
        'emoji' => '◾',
        'bg'    => '#f5f5f0',
        'card'  => '#fff',
        'border'=> '#222',
        'text'  => '#111',
        'sub'   => '#555',
        'btn'   => 'linear-gradient(90deg,#111,#333)',
        'btnTxt'=> '#fff',
        'glow'  => '0 2px 20px rgba(0,0,0,0.12)',
        'font'  => "'DM Sans', sans-serif",
        'bodyFont' => "'DM Sans', sans-serif",
        'extra' => 'clean',
    ],
];

// ─── RENDER PUBLIC PAGE ─────────────────────────────────────
function renderPublicPage(array $member, array $links, array $videos, array $tpl): void {
    $dn = htmlspecialchars($member['display_name'] ?: $member['username']);
    $bio = htmlspecialchars($member['bio'] ?? '');
    $handle = htmlspecialchars($member['tiktok_handle'] ?? '');
    $avatar = htmlspecialchars($member['avatar_url'] ?? '');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $dn ?> | TikLinks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;700&family=Cinzel:wght@400;700&family=Crimson+Text:ital,wght@0,400;1,400&family=Pacifico&family=Quicksand:wght@400;600&family=DM+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:<?= $tpl['bg'] ?>;
  --card:<?= $tpl['card'] ?>;
  --border:<?= $tpl['border'] ?>;
  --text:<?= $tpl['text'] ?>;
  --sub:<?= $tpl['sub'] ?>;
  --btn:<?= $tpl['btn'] ?>;
  --btnTxt:<?= $tpl['btnTxt'] ?>;
  --glow:<?= $tpl['glow'] ?>;
  --font:<?= $tpl['font'] ?>;
  --bodyFont:<?= $tpl['bodyFont'] ?>;
}
body{
  background:var(--bg);
  color:var(--text);
  font-family:var(--bodyFont);
  min-height:100vh;
  overflow-x:hidden;
}
<?php if($tpl['extra']==='scanlines'): ?>
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,255,255,0.015) 2px,rgba(0,255,255,0.015) 4px);pointer-events:none;z-index:9999}
<?php elseif($tpl['extra']==='noise'): ?>
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;opacity:.6}
<?php elseif($tpl['extra']==='grid'): ?>
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(180,160,255,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(180,160,255,.07) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;z-index:0}
<?php elseif($tpl['extra']==='grain'): ?>
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='turbulence' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23g)' opacity='0.06'/%3E%3C/svg%3E");pointer-events:none;z-index:9999}
<?php endif; ?>
.container{max-width:540px;margin:0 auto;padding:40px 20px 80px}
.avatar-wrap{display:flex;justify-content:center;margin-bottom:20px}
.avatar{
  width:96px;height:96px;border-radius:50%;
  border:3px solid var(--border);
  box-shadow:var(--glow);
  object-fit:cover;
  background:var(--card);
  display:flex;align-items:center;justify-content:center;
  font-size:40px;
  overflow:hidden;
}
.profile-name{font-family:var(--font);text-align:center;font-size:clamp(1.4rem,5vw,2rem);color:var(--text);text-shadow:var(--glow);margin-bottom:6px}
.tiktok-handle{text-align:center;color:var(--sub);font-size:.9rem;margin-bottom:12px;opacity:.9}
.bio{text-align:center;color:var(--text);opacity:.7;font-size:.92rem;margin-bottom:30px;line-height:1.5;max-width:380px;margin-left:auto;margin-right:auto}
.section-title{font-family:var(--font);color:var(--sub);font-size:.75rem;letter-spacing:3px;text-transform:uppercase;margin-bottom:14px;opacity:.7}
.link-btn{
  display:flex;align-items:center;gap:14px;
  width:100%;padding:15px 22px;
  background:var(--card);
  border:1.5px solid var(--border);
  border-radius:12px;
  color:var(--text);
  text-decoration:none;
  font-family:var(--bodyFont);font-size:1rem;font-weight:600;
  margin-bottom:12px;
  transition:all .25s;
  box-shadow:0 2px 10px rgba(0,0,0,.2);
  position:relative;overflow:hidden;
}
.link-btn::before{
  content:'';position:absolute;inset:0;
  background:var(--btn);opacity:0;
  transition:opacity .3s;
}
.link-btn:hover::before{opacity:.15}
.link-btn:hover{
  border-color:var(--sub);
  box-shadow:var(--glow);
  transform:translateY(-2px);
}
.link-icon{font-size:1.4rem;min-width:28px;text-align:center}
.link-arrow{margin-left:auto;opacity:.5;transition:all .3s}
.link-btn:hover .link-arrow{opacity:1;transform:translateX(4px)}
.videos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-top:14px}
.video-card{
  border:1.5px solid var(--border);border-radius:10px;
  overflow:hidden;
  background:var(--card);
  transition:all .25s;
}
.video-card:hover{box-shadow:var(--glow);transform:scale(1.02)}
.video-embed{aspect-ratio:9/16;width:100%;background:#000}
.video-embed iframe{width:100%;height:100%;border:none}
.video-caption{padding:8px 10px;font-size:.78rem;color:var(--text);opacity:.7;line-height:1.3}
.tiktok-badge{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:50px;
  background:var(--btn);color:var(--btnTxt);
  font-family:var(--font);font-size:.8rem;font-weight:700;
  text-decoration:none;margin-top:8px;margin-bottom:26px;
  box-shadow:var(--glow);
  transition:all .25s;
}
.tiktok-badge:hover{transform:scale(1.05);filter:brightness(1.1)}
.powered{text-align:center;margin-top:50px;font-size:.72rem;opacity:.3;color:var(--text)}
.powered a{color:var(--sub);text-decoration:none}
.fade-in{animation:fadeIn .5s ease both}
@keyframes fadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
<?php if($tpl['extra']==='cyberpunk'||$tpl['extra']==='scanlines'): ?>
@keyframes flicker{0%,100%{opacity:1}92%{opacity:1}93%{opacity:.8}94%{opacity:1}97%{opacity:.9}98%{opacity:1}}
.profile-name{animation:flicker 4s infinite}
<?php endif; ?>
</style>
</head>
<body>
<div class="container">
  <div class="avatar-wrap fade-in" style="animation-delay:.05s">
    <div class="avatar">
      <?php if($avatar): ?><img src="<?= $avatar ?>" alt="<?= $dn ?>" style="width:100%;height:100%;object-fit:cover">
      <?php else: ?>👤<?php endif; ?>
    </div>
  </div>
  <div class="profile-name fade-in" style="animation-delay:.1s"><?= $dn ?></div>
  <?php if($handle): ?>
  <div class="tiktok-handle fade-in" style="animation-delay:.15s">
    <a href="https://tiktok.com/@<?= $handle ?>" target="_blank" class="tiktok-badge">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.75a8.28 8.28 0 004.83 1.54V6.85a4.85 4.85 0 01-1.06-.16z"/></svg>
      @<?= $handle ?>
    </a>
  </div>
  <?php endif; ?>
  <?php if($bio): ?><div class="bio fade-in" style="animation-delay:.2s"><?= nl2br($bio) ?></div><?php endif; ?>

  <?php if(!empty($links)): ?>
  <div class="fade-in" style="animation-delay:.25s">
    <div class="section-title">Mes liens</div>
    <?php foreach($links as $i=>$link): ?>
    <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener" class="link-btn" style="animation-delay:<?= .3+$i*.07 ?>s">
      <span class="link-icon"><?= htmlspecialchars($link['icon']) ?></span>
      <span><?= htmlspecialchars($link['title']) ?></span>
      <span class="link-arrow">→</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if(!empty($videos)): ?>
  <div class="fade-in" style="animation-delay:.5s;margin-top:30px">
    <div class="section-title">Mes meilleures vidéos</div>
    <div class="videos-grid">
      <?php foreach($videos as $v): 
        // Extract TikTok video ID
        preg_match('/video\/(\d+)/', $v['video_url'], $m2);
        $vid = $m2[1] ?? '';
      ?>
      <div class="video-card">
        <?php if($vid): ?>
        <div class="video-embed">
          <iframe src="https://www.tiktok.com/embed/v2/<?= $vid ?>" allowfullscreen scrolling="no" allow="encrypted-media"></iframe>
        </div>
        <?php else: ?>
        <div class="video-embed" style="display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:2rem">▶</div>
        <?php endif; ?>
        <?php if($v['caption']): ?><div class="video-caption"><?= htmlspecialchars($v['caption']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="powered">Propulsé par <a href="<?= SITE_URL ?>">TikLinks</a> · Crée ta page <a href="<?= SITE_URL ?>?action=register">gratuitement</a></div>
</div>
</body>
</html><?php
}

// ─── ADMIN UI ───────────────────────────────────────────────
function renderAdmin(array $member, PDO $db, array $tpls): void {
    $tab = $_GET['tab'] ?? 'profile';
    $saved = isset($_GET['saved']);
    $username = $member['username'];

    // Load links & videos
    $ls = $db->prepare("SELECT * FROM links WHERE member_id=? ORDER BY sort_order,id");
    $ls->execute([$member['id']]);
    $links = $ls->fetchAll(PDO::FETCH_ASSOC);

    $vs = $db->prepare("SELECT * FROM tiktok_videos WHERE member_id=? ORDER BY sort_order,id");
    $vs->execute([$member['id']]);
    $videos = $vs->fetchAll(PDO::FETCH_ASSOC);

    $currentTpl = $tpls[$member['template']] ?? $tpls['cyberpunk'];
    $pubUrl = SITE_URL . '?u=' . urlencode($username);
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — TikLinks</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;700&family=DM+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#0d0d14;--sidebar:#0a0a10;--card:rgba(255,255,255,.04);
  --border:rgba(0,255,255,.2);--accent:#00ffff;--accent2:#ff00ff;
  --text:#e0e8ff;--sub:#7080a0;--danger:#ff3355;--success:#00ff88;
  --font:'Orbitron',monospace;--body:'Share Tech Mono',monospace;
}
body{background:var(--bg);color:var(--text);font-family:var(--body);min-height:100vh;display:flex;flex-direction:column}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 20%,rgba(0,255,255,.04) 0,transparent 60%),radial-gradient(ellipse at 80% 80%,rgba(255,0,255,.04) 0,transparent 60%);pointer-events:none}
.topbar{
  background:var(--sidebar);border-bottom:1px solid var(--border);
  padding:14px 24px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;
}
.logo{font-family:var(--font);font-size:1.1rem;color:var(--accent);text-shadow:0 0 10px var(--accent);letter-spacing:2px}
.logo span{color:var(--accent2)}
.topbar-right{display:flex;align-items:center;gap:12px}
.user-chip{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:6px 14px;font-size:.8rem;color:var(--text)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-family:var(--body);font-size:.85rem;font-weight:600;transition:all .2s;text-decoration:none}
.btn-primary{background:linear-gradient(90deg,var(--accent),var(--accent2));color:#000}
.btn-primary:hover{filter:brightness(1.15);transform:translateY(-1px);box-shadow:0 0 15px rgba(0,255,255,.3)}
.btn-danger{background:rgba(255,51,85,.15);border:1px solid var(--danger);color:var(--danger)}
.btn-danger:hover{background:rgba(255,51,85,.3)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--text)}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}
.btn-sm{padding:5px 10px;font-size:.75rem}
.main{display:flex;flex:1;overflow:hidden}
.sidebar{width:220px;background:var(--sidebar);border-right:1px solid var(--border);padding:20px 0;display:flex;flex-direction:column}
.nav-item{display:flex;align-items:center;gap:10px;padding:12px 20px;color:var(--sub);font-size:.82rem;cursor:pointer;transition:all .2s;text-decoration:none;border-left:3px solid transparent}
.nav-item:hover{color:var(--text);background:var(--card)}
.nav-item.active{color:var(--accent);border-left-color:var(--accent);background:rgba(0,255,255,.05)}
.nav-icon{font-size:1.1rem;min-width:20px;text-align:center}
.preview-btn{margin:16px;padding:10px;background:linear-gradient(90deg,var(--accent),var(--accent2));color:#000;text-align:center;border-radius:8px;font-size:.78rem;font-weight:700;text-decoration:none;display:block;transition:all .2s}
.preview-btn:hover{filter:brightness(1.1);transform:scale(1.02)}
.content{flex:1;padding:28px;overflow-y:auto}
.page-title{font-family:var(--font);font-size:1.3rem;color:var(--text);margin-bottom:6px}
.page-sub{color:var(--sub);font-size:.82rem;margin-bottom:24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:22px;margin-bottom:18px}
.card-title{font-family:var(--font);font-size:.85rem;color:var(--accent);margin-bottom:16px;letter-spacing:1px}
.form-group{margin-bottom:16px}
label{display:block;font-size:.78rem;color:var(--sub);margin-bottom:6px;letter-spacing:.5px}
input,textarea,select{
  width:100%;padding:10px 14px;
  background:rgba(255,255,255,.04);
  border:1px solid var(--border);
  border-radius:8px;
  color:var(--text);
  font-family:var(--body);font-size:.9rem;
  transition:border-color .2s;
  outline:none;
}
input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(0,255,255,.1)}
textarea{resize:vertical;min-height:80px}
select option{background:#1a1a2e;color:var(--text)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.template-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px}
.tpl-card{
  border:2px solid transparent;border-radius:10px;padding:14px;
  cursor:pointer;transition:all .2s;text-align:center;
  background:var(--card);
}
.tpl-card:hover{transform:scale(1.03)}
.tpl-card.selected{border-color:var(--accent);box-shadow:0 0 12px rgba(0,255,255,.3)}
.tpl-card input[type=radio]{display:none}
.tpl-emoji{font-size:1.8rem;margin-bottom:6px}
.tpl-name{font-family:var(--font);font-size:.72rem;letter-spacing:1px}
.links-list{list-style:none}
.link-row{
  display:flex;align-items:center;gap:10px;
  padding:12px 16px;background:var(--card);
  border:1px solid var(--border);border-radius:8px;
  margin-bottom:8px;transition:all .2s;
}
.link-row:hover{border-color:var(--accent)}
.link-row-icon{font-size:1.2rem;min-width:28px;text-align:center}
.link-row-info{flex:1;min-width:0}
.link-row-title{font-size:.9rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.link-row-url{font-size:.72rem;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.link-row-actions{display:flex;gap:6px;flex-shrink:0}
.badge{display:inline-flex;padding:3px 8px;border-radius:4px;font-size:.68rem;font-weight:700}
.badge-on{background:rgba(0,255,136,.15);color:var(--success);border:1px solid rgba(0,255,136,.3)}
.badge-off{background:rgba(255,51,85,.1);color:var(--danger);border:1px solid rgba(255,51,85,.2)}
.alert-success{background:rgba(0,255,136,.1);border:1px solid rgba(0,255,136,.3);color:var(--success);padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem}
.url-display{background:rgba(0,255,255,.05);border:1px solid rgba(0,255,255,.2);border-radius:8px;padding:10px 16px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.url-text{font-size:.85rem;color:var(--accent);word-break:break-all}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center}
.stat-num{font-family:var(--font);font-size:1.8rem;color:var(--accent);text-shadow:0 0 10px rgba(0,255,255,.4)}
.stat-label{font-size:.72rem;color:var(--sub);margin-top:4px}
.empty-state{text-align:center;padding:40px;color:var(--sub);font-size:.88rem;border:1px dashed var(--border);border-radius:10px}
.icon-picker{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.icon-btn{width:36px;height:36px;border:1px solid var(--border);border-radius:6px;background:var(--card);cursor:pointer;font-size:1.1rem;transition:all .15s;display:flex;align-items:center;justify-content:center}
.icon-btn:hover,.icon-btn.active{border-color:var(--accent);background:rgba(0,255,255,.1)}
@media(max-width:768px){
  .main{flex-direction:column}
  .sidebar{width:100%;flex-direction:row;overflow-x:auto;padding:0}
  .nav-item{padding:10px 14px;border-left:none;border-bottom:3px solid transparent;white-space:nowrap}
  .nav-item.active{border-bottom-color:var(--accent);border-left-color:transparent}
  .form-row{grid-template-columns:1fr}
  .stats-row{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<div class="topbar">
  <div class="logo">Tik<span>Links</span></div>
  <div class="topbar-right">
    <span class="user-chip">@<?= htmlspecialchars($username) ?></span>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="logout"><button type="submit" class="btn btn-ghost btn-sm">Déconnexion</button></form>
  </div>
</div>
<div class="main">
  <div class="sidebar">
    <a href="?action=admin&tab=profile" class="nav-item <?= $tab==='profile'?'active':'' ?>"><span class="nav-icon">👤</span> Profil</a>
    <a href="?action=admin&tab=links" class="nav-item <?= $tab==='links'?'active':'' ?>"><span class="nav-icon">🔗</span> Mes Liens</a>
    <a href="?action=admin&tab=videos" class="nav-item <?= $tab==='videos'?'active':'' ?>"><span class="nav-icon">🎵</span> Vidéos TikTok</a>
    <a href="?action=admin&tab=theme" class="nav-item <?= $tab==='theme'?'active':'' ?>"><span class="nav-icon">🎨</span> Thème</a>
    <a href="<?= htmlspecialchars($pubUrl) ?>" target="_blank" class="preview-btn" style="margin-top:auto">👁 Voir ma page</a>
  </div>
  <div class="content">
    <?php if($saved): ?><div class="alert-success">✅ Modifications sauvegardées !</div><?php endif; ?>

    <div class="url-display">
      <span class="url-text"><?= htmlspecialchars($pubUrl) ?></span>
      <button class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($pubUrl) ?>');this.textContent='✅ Copié!'">📋 Copier</button>
    </div>

    <?php if($tab==='profile'): ?>
    <div class="page-title">Mon Profil</div>
    <div class="page-sub">Personnalise les infos de ta page publique</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_profile">
      <div class="card">
        <div class="card-title">INFORMATIONS</div>
        <div class="form-row">
          <div class="form-group">
            <label>Nom affiché</label>
            <input type="text" name="display_name" value="<?= htmlspecialchars($member['display_name']) ?>" placeholder="Ton nom ou pseudo">
          </div>
          <div class="form-group">
            <label>@ TikTok</label>
            <input type="text" name="tiktok_handle" value="<?= htmlspecialchars($member['tiktok_handle']) ?>" placeholder="tonpseudo (sans @)">
          </div>
        </div>
        <div class="form-group">
          <label>Bio</label>
          <textarea name="bio" placeholder="Décris-toi en quelques mots..."><?= htmlspecialchars($member['bio']) ?></textarea>
        </div>
        <div class="form-group">
          <label>URL Photo de profil</label>
          <input type="url" name="avatar_url" value="<?= htmlspecialchars($member['avatar_url']) ?>" placeholder="https://...">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Sauvegarder le profil</button>
    </form>

    <?php elseif($tab==='links'): ?>
    <div class="page-title">Mes Liens</div>
    <div class="page-sub">Ajoute et gère tes liens personnalisés</div>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-num"><?= count($links) ?></div><div class="stat-label">Liens totaux</div></div>
      <div class="stat-card"><div class="stat-num"><?= count(array_filter($links,fn($l)=>$l['active'])) ?></div><div class="stat-label">Actifs</div></div>
      <div class="stat-card"><div class="stat-num"><?= count(array_filter($links,fn($l)=>!$l['active'])) ?></div><div class="stat-label">Masqués</div></div>
    </div>

    <div class="card">
      <div class="card-title">AJOUTER UN LIEN</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_link">
        <input type="hidden" name="sort_order" value="<?= count($links) ?>">
        <div class="form-row">
          <div class="form-group">
            <label>Titre</label>
            <input type="text" name="title" placeholder="Mon Instagram" required>
          </div>
          <div class="form-group">
            <label>URL</label>
            <input type="url" name="url" placeholder="https://instagram.com/..." required>
          </div>
        </div>
        <div class="form-group">
          <label>Icône</label>
          <input type="text" name="icon" id="icon-input" value="🔗" style="width:60px;text-align:center;font-size:1.3rem">
          <div class="icon-picker">
            <?php foreach(['🔗','📱','🎵','📸','🎬','💬','🌐','🛍️','🎮','💌','🎙️','📺','👾','🔥','⭐','💡','🎤','🎧','🎨','💰'] as $ic): ?>
            <button type="button" class="icon-btn" onclick="document.getElementById('icon-input').value='<?= $ic ?>'"><?= $ic ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">+ Ajouter le lien</button>
      </form>
    </div>

    <?php if(!empty($links)): ?>
    <div class="card">
      <div class="card-title">MES LIENS (<?= count($links) ?>)</div>
      <ul class="links-list">
        <?php foreach($links as $link): ?>
        <li class="link-row">
          <span class="link-row-icon"><?= htmlspecialchars($link['icon']) ?></span>
          <div class="link-row-info">
            <div class="link-row-title"><?= htmlspecialchars($link['title']) ?></div>
            <div class="link-row-url"><?= htmlspecialchars($link['url']) ?></div>
          </div>
          <span class="badge <?= $link['active']?'badge-on':'badge-off' ?>"><?= $link['active']?'ON':'OFF' ?></span>
          <div class="link-row-actions">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_link">
              <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm"><?= $link['active']?'🙈':'👁' ?></button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce lien ?')">
              <input type="hidden" name="action" value="delete_link">
              <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?>
    <div class="empty-state">🔗 Aucun lien pour l'instant. Ajoute ton premier lien ci-dessus !</div>
    <?php endif; ?>

    <?php elseif($tab==='videos'): ?>
    <div class="page-title">Vidéos TikTok</div>
    <div class="page-sub">Mets en avant tes meilleures vidéos</div>

    <div class="card">
      <div class="card-title">AJOUTER UNE VIDÉO</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_video">
        <input type="hidden" name="sort_order" value="<?= count($videos) ?>">
        <div class="form-group">
          <label>URL de la vidéo TikTok</label>
          <input type="url" name="video_url" placeholder="https://www.tiktok.com/@pseudo/video/1234567890" required>
        </div>
        <div class="form-group">
          <label>Légende (optionnel)</label>
          <input type="text" name="caption" placeholder="Ma meilleure vidéo 🔥">
        </div>
        <button type="submit" class="btn btn-primary">+ Ajouter la vidéo</button>
      </form>
    </div>

    <?php if(!empty($videos)): ?>
    <div class="card">
      <div class="card-title">MES VIDÉOS (<?= count($videos) ?>)</div>
      <ul class="links-list">
        <?php foreach($videos as $v): ?>
        <li class="link-row">
          <span class="link-row-icon">🎵</span>
          <div class="link-row-info">
            <div class="link-row-title"><?= htmlspecialchars($v['caption'] ?: 'Vidéo TikTok') ?></div>
            <div class="link-row-url"><?= htmlspecialchars($v['video_url']) ?></div>
          </div>
          <div class="link-row-actions">
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette vidéo ?')">
              <input type="hidden" name="action" value="delete_video">
              <input type="hidden" name="video_id" value="<?= $v['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?>
    <div class="empty-state">🎵 Aucune vidéo ajoutée. Colle l'URL d'une vidéo TikTok !</div>
    <?php endif; ?>

    <?php elseif($tab==='theme'): ?>
    <div class="page-title">Thème de ma page</div>
    <div class="page-sub">Choisis le style visuel de ta page publique</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_profile">
      <input type="hidden" name="display_name" value="<?= htmlspecialchars($member['display_name']) ?>">
      <input type="hidden" name="bio" value="<?= htmlspecialchars($member['bio']) ?>">
      <input type="hidden" name="tiktok_handle" value="<?= htmlspecialchars($member['tiktok_handle']) ?>">
      <input type="hidden" name="avatar_url" value="<?= htmlspecialchars($member['avatar_url']) ?>">
      <div class="card">
        <div class="card-title">CHOISIR UN TEMPLATE</div>
        <div class="template-grid">
          <?php foreach($tpls as $key=>$tpl): ?>
          <label class="tpl-card <?= $member['template']===$key?'selected':'' ?>" style="background:<?= $tpl['bg'] ?>;border-color:<?= $member['template']===$key?$tpl['border']:'transparent' ?>">
            <input type="radio" name="template" value="<?= $key ?>" <?= $member['template']===$key?'checked':'' ?> onchange="document.querySelectorAll('.tpl-card').forEach(c=>c.classList.remove('selected'));this.closest('.tpl-card').classList.add('selected')">
            <div class="tpl-emoji"><?= $tpl['emoji'] ?></div>
            <div class="tpl-name" style="color:<?= $tpl['text'] ?>;font-family:<?= $tpl['font'] ?>"><?= $tpl['name'] ?></div>
            <div style="margin-top:8px;height:4px;border-radius:2px;background:<?= $tpl['btn'] ?>"></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">🎨 Appliquer le thème</button>
    </form>
    <?php endif; ?>

  </div>
</div>
</body>
</html><?php
}

// ─── AUTH PAGES ─────────────────────────────────────────────
function renderAuthPage(string $mode, ?string $error = null): void { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TikLinks — <?= $mode==='register'?'Créer un compte':'Connexion' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
  background:#0a0a0f;color:#e0e8ff;font-family:'Share Tech Mono',monospace;
  min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;
  background-image:radial-gradient(ellipse at 30% 30%,rgba(0,255,255,.06),transparent 60%),radial-gradient(ellipse at 70% 70%,rgba(255,0,255,.06),transparent 60%);
}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,255,255,.01) 2px,rgba(0,255,255,.01) 4px);pointer-events:none}
.box{width:100%;max-width:400px;padding:20px}
.logo{font-family:'Orbitron',monospace;font-size:2rem;color:#00ffff;text-shadow:0 0 20px #00ffff,0 0 40px rgba(0,255,255,.3);text-align:center;margin-bottom:8px}
.logo span{color:#ff00ff}
.tagline{text-align:center;color:#7080a0;font-size:.78rem;margin-bottom:32px;letter-spacing:1px}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(0,255,255,.2);border-radius:14px;padding:28px}
.card-title{font-family:'Orbitron',monospace;font-size:.9rem;color:#00ffff;margin-bottom:20px;text-align:center;letter-spacing:2px}
.form-group{margin-bottom:16px}
label{display:block;font-size:.75rem;color:#7080a0;margin-bottom:6px}
input{width:100%;padding:11px 14px;background:rgba(255,255,255,.04);border:1px solid rgba(0,255,255,.2);border-radius:8px;color:#e0e8ff;font-family:'Share Tech Mono',monospace;font-size:.9rem;outline:none;transition:all .2s}
input:focus{border-color:#00ffff;box-shadow:0 0 0 2px rgba(0,255,255,.1)}
.btn{width:100%;padding:12px;border:none;border-radius:8px;background:linear-gradient(90deg,#00ffff,#ff00ff);color:#000;font-family:'Share Tech Mono',monospace;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .25s;margin-top:6px}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);box-shadow:0 4px 20px rgba(0,255,255,.3)}
.error{background:rgba(255,51,85,.1);border:1px solid rgba(255,51,85,.3);color:#ff3355;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:14px}
.switch{text-align:center;margin-top:16px;font-size:.8rem;color:#7080a0}
.switch a{color:#00ffff;text-decoration:none}
.switch a:hover{text-shadow:0 0 8px #00ffff}
</style>
</head>
<body>
<div class="box">
  <div class="logo">Tik<span>Links</span></div>
  <div class="tagline">// crée ta page de liens TikTok //</div>
  <div class="card">
    <div class="card-title"><?= $mode==='register'?'CRÉER UN COMPTE':'CONNEXION' ?></div>
    <?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="<?= $mode ?>">
      <div class="form-group">
        <label>Nom d'utilisateur</label>
        <input type="text" name="username" placeholder="tonpseudo" required autocomplete="username">
      </div>
      <?php if($mode==='register'): ?>
      <div class="form-group">
        <label>Nom affiché</label>
        <input type="text" name="display_name" placeholder="Ton vrai nom ou pseudo">
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="••••••••" required autocomplete="<?= $mode==='register'?'new-password':'current-password' ?>">
      </div>
      <button type="submit" class="btn"><?= $mode==='register'?'🚀 Créer ma page':'⚡ Se connecter' ?></button>
    </form>
    <div class="switch">
      <?php if($mode==='register'): ?>
        Déjà un compte ? <a href="?action=login">Connexion</a>
      <?php else: ?>
        Pas encore de compte ? <a href="?action=register">Créer ma page</a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html><?php
}

// ─── HOME PAGE ──────────────────────────────────────────────
function renderHome(): void { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TikLinks — Crée ta page de liens TikTok</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0a0f;color:#e0e8ff;font-family:'Share Tech Mono',monospace;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px;background-image:radial-gradient(ellipse at 20% 20%,rgba(0,255,255,.08),transparent 60%),radial-gradient(ellipse at 80% 80%,rgba(255,0,255,.08),transparent 60%)}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,255,255,.012) 2px,rgba(0,255,255,.012) 4px);pointer-events:none}
.logo{font-family:'Orbitron',monospace;font-size:clamp(2.5rem,8vw,4rem);color:#00ffff;text-shadow:0 0 30px #00ffff,0 0 60px rgba(0,255,255,.4);animation:pulse 3s ease-in-out infinite}
.logo span{color:#ff00ff;text-shadow:0 0 30px #ff00ff}
@keyframes pulse{0%,100%{text-shadow:0 0 30px #00ffff,0 0 60px rgba(0,255,255,.4)}50%{text-shadow:0 0 50px #00ffff,0 0 100px rgba(0,255,255,.6)}}
.tagline{font-size:clamp(.9rem,2.5vw,1.1rem);color:#7080a0;margin:14px 0 40px;line-height:1.6;max-width:480px}
.ctas{display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
.btn{padding:13px 28px;border-radius:10px;font-family:'Share Tech Mono',monospace;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .25s;text-decoration:none;border:none}
.btn-primary{background:linear-gradient(90deg,#00ffff,#ff00ff);color:#000}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-2px);box-shadow:0 6px 25px rgba(0,255,255,.4)}
.btn-ghost{background:transparent;border:2px solid rgba(0,255,255,.3);color:#00ffff}
.btn-ghost:hover{border-color:#00ffff;box-shadow:0 0 15px rgba(0,255,255,.2);transform:translateY(-2px)}
.features{display:flex;gap:18px;margin-top:50px;flex-wrap:wrap;justify-content:center;max-width:600px}
.feat{background:rgba(255,255,255,.03);border:1px solid rgba(0,255,255,.15);border-radius:12px;padding:18px 20px;flex:1;min-width:160px;text-align:left}
.feat-icon{font-size:1.5rem;margin-bottom:8px}
.feat-title{font-family:'Orbitron',monospace;font-size:.7rem;color:#00ffff;margin-bottom:4px;letter-spacing:1px}
.feat-desc{font-size:.72rem;color:#7080a0;line-height:1.4}
</style>
</head>
<body>
  <div class="logo">Tik<span>Links</span></div>
  <p class="tagline">Crée ta page de liens personnalisée pour TikTok.<br>Cinq thèmes. Vidéos intégrées. Admin complet.</p>
  <div class="ctas">
    <a href="?action=register" class="btn btn-primary">🚀 Créer ma page</a>
    <a href="?action=login" class="btn btn-ghost">⚡ Connexion</a>
  </div>
  <div class="features">
    <div class="feat"><div class="feat-icon">🎨</div><div class="feat-title">5 THÈMES</div><div class="feat-desc">CyberPunk, Punk, Artiste, Vaporwave, Minimaliste</div></div>
    <div class="feat"><div class="feat-icon">🔗</div><div class="feat-title">LIENS ILLIMITÉS</div><div class="feat-desc">Ajoute tous tes réseaux avec icônes</div></div>
    <div class="feat"><div class="feat-icon">🎵</div><div class="feat-title">VIDÉOS TIKTOK</div><div class="feat-desc">Intègre tes meilleures vidéos directement</div></div>
    <div class="feat"><div class="feat-icon">⚡</div><div class="feat-title">PAGE RAPIDE</div><div class="feat-desc">PHP + SQLite, aucune dépendance lourde</div></div>
  </div>
</body>
</html><?php
}

// ─── MAIN DISPATCH ───────────────────────────────────────────
if (!empty($slug)) {
    // Public profile page
    $s = $db->prepare("SELECT * FROM members WHERE username=?");
    $s->execute([$slug]);
    $member = $s->fetch(PDO::FETCH_ASSOC);
    if (!$member) { http_response_code(404); die('<h1>Page introuvable</h1>'); }

    $ls = $db->prepare("SELECT * FROM links WHERE member_id=? AND active=1 ORDER BY sort_order,id");
    $ls->execute([$member['id']]);
    $links = $ls->fetchAll(PDO::FETCH_ASSOC);

    $vs = $db->prepare("SELECT * FROM tiktok_videos WHERE member_id=? ORDER BY sort_order,id");
    $vs->execute([$member['id']]);
    $videos = $vs->fetchAll(PDO::FETCH_ASSOC);

    $tpl = $templates[$member['template']] ?? $templates['cyberpunk'];
    renderPublicPage($member, $links, $videos, $tpl);

} elseif ($action === 'admin') {
    if (!isLoggedIn()) { header('Location: ' . SITE_URL . '?action=login'); exit; }
    $member = currentMember();
    if (!$member) { session_destroy(); header('Location: ' . SITE_URL . '?action=login'); exit; }
    renderAdmin($member, $db, $templates);

} elseif ($action === 'register') {
    if (isLoggedIn()) { header('Location: ' . SITE_URL . '?action=admin'); exit; }
    renderAuthPage('register', $error ?? null);

} elseif ($action === 'login') {
    if (isLoggedIn()) { header('Location: ' . SITE_URL . '?action=admin'); exit; }
    renderAuthPage('login', $error ?? null);

} else {
    renderHome();
}

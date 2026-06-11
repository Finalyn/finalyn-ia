<?php
require __DIR__ . '/auth.php';
finalyn_require_login();

$pdo = finalyn_db();
$today = gmdate('Y-m-d');
$flash = '';

// ---------- Actions (POST + CSRF) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!finalyn_csrf_ok($_POST['csrf'] ?? '')) {
        $flash = 'Action refusee (jeton invalide). Reessayez.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'cancel_booking' && !empty($_POST['id'])) {
            $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([(int)$_POST['id']]);
            $flash = 'Reservation annulee.';
        } elseif ($action === 'done_booking' && !empty($_POST['id'])) {
            $pdo->prepare("UPDATE bookings SET status='done' WHERE id=?")->execute([(int)$_POST['id']]);
            $flash = 'Reservation marquee comme faite.';
        } elseif ($action === 'block_date' && !empty($_POST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'])) {
            $pdo->prepare('INSERT OR IGNORE INTO blocked_dates (slot_date, created_at) VALUES (?,?)')
                ->execute([$_POST['date'], gmdate('Y-m-d H:i:s')]);
            $flash = 'Date bloquee.';
        } elseif ($action === 'unblock_date' && !empty($_POST['date'])) {
            $pdo->prepare('DELETE FROM blocked_dates WHERE slot_date=?')->execute([$_POST['date']]);
            $flash = 'Date debloquee.';
        }
    }
    // Evite le renvoi de formulaire
    $_SESSION['flash'] = $flash;
    header('Location: index.php');
    exit;
}
if (!empty($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$csrf = finalyn_csrf();

// ---------- Helpers requetes ----------
function scalar($pdo, $sql, $params = []) {
    $s = $pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn();
}
$d7  = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
$d30 = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

// ---------- KPIs ----------
$kpi = [
    'conv_total'   => scalar($pdo, 'SELECT COUNT(*) FROM conversations'),
    'conv_7'       => scalar($pdo, 'SELECT COUNT(*) FROM conversations WHERE started_at >= ?', [$d7]),
    'msg_total'    => scalar($pdo, 'SELECT COUNT(*) FROM messages'),
    'book_up'      => scalar($pdo, "SELECT COUNT(*) FROM bookings WHERE status='confirmed' AND slot_date >= ?", [$today]),
    'book_total'   => scalar($pdo, "SELECT COUNT(*) FROM bookings WHERE status!='cancelled'"),
    'views_7'      => scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$d7]),
    'views_30'     => scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$d30]),
    'views_today'  => scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$today . ' 00:00:00']),
];

// ---------- Reservations ----------
$upcoming = $pdo->prepare("SELECT * FROM bookings WHERE status='confirmed' AND slot_date >= ? ORDER BY slot_date, slot_time");
$upcoming->execute([$today]);
$upcoming = $upcoming->fetchAll(PDO::FETCH_ASSOC);

$recent = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);

$blocked = $pdo->prepare('SELECT slot_date FROM blocked_dates WHERE slot_date >= ? ORDER BY slot_date');
$blocked->execute([$today]);
$blocked = $blocked->fetchAll(PDO::FETCH_COLUMN);

// ---------- Conversations (30 dernieres) ----------
$convs = $pdo->query("SELECT * FROM conversations ORDER BY last_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$msgStmt = $pdo->prepare('SELECT role, content, created_at FROM messages WHERE conversation_id=? ORDER BY id');

// ---------- Stats site & blog ----------
$daily = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY d ORDER BY d");
$daily->execute([gmdate('Y-m-d', strtotime('-13 days')) . ' 00:00:00']);
$daily = $daily->fetchAll(PDO::FETCH_KEY_PAIR);

$topPages = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY path ORDER BY c DESC LIMIT 12");
$topPages->execute([$d30]);
$topPages = $topPages->fetchAll(PDO::FETCH_ASSOC);

$topBlog = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? AND path LIKE '/blog/%' GROUP BY path ORDER BY c DESC LIMIT 12");
$topBlog->execute([$d30]);
$topBlog = $topBlog->fetchAll(PDO::FETCH_ASSOC);

$maxDaily = 1;
foreach ($daily as $c) { if ($c > $maxDaily) $maxDaily = $c; }

function fr_dt($s) { // 'Y-m-d H:i:s' -> '11.06.2026 14:03'
    $t = strtotime($s . ' UTC');
    return $t ? date('d.m.Y H:i', $t) : h($s);
}
function fr_d($s) {
    $t = strtotime($s);
    return $t ? date('d.m.Y', $t) : h($s);
}
?>
<!DOCTYPE html>
<html lang="fr-CH">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>Back-office · finalyn.ia</title>
<link rel="stylesheet" href="style.css" />
</head>
<body>

<header class="adm-top">
  <div class="adm-brand">finalyn<span class="ia">.ia</span> <small>back-office</small></div>
  <nav class="adm-nav">
    <a href="#kpis">Vue d'ensemble</a>
    <a href="#reservations">Reservations</a>
    <a href="#conversations">Conversations</a>
    <a href="#stats">Stats site &amp; blog</a>
    <a class="adm-logout" href="logout.php">Deconnexion</a>
  </nav>
</header>

<main class="adm-main">

<?php if ($flash): ?><div class="adm-flash"><?= h($flash) ?></div><?php endif; ?>

<!-- KPIs -->
<section id="kpis" class="adm-section">
  <h1>Vue d'ensemble</h1>
  <div class="adm-kpis">
    <div class="kpi"><span class="kpi-num"><?= $kpi['book_up'] ?></span><span class="kpi-lbl">RDV a venir</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['conv_7'] ?></span><span class="kpi-lbl">Conversations (7 j)</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['conv_total'] ?></span><span class="kpi-lbl">Conversations (total)</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['msg_total'] ?></span><span class="kpi-lbl">Messages echanges</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['views_today'] ?></span><span class="kpi-lbl">Pages vues (aujourd'hui)</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['views_7'] ?></span><span class="kpi-lbl">Pages vues (7 j)</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['views_30'] ?></span><span class="kpi-lbl">Pages vues (30 j)</span></div>
    <div class="kpi"><span class="kpi-num"><?= $kpi['book_total'] ?></span><span class="kpi-lbl">RDV (total)</span></div>
  </div>
</section>

<!-- Reservations -->
<section id="reservations" class="adm-section">
  <h2>Reservations a venir</h2>
  <?php if (!$upcoming): ?>
    <p class="adm-empty">Aucun rendez-vous a venir pour le moment.</p>
  <?php else: ?>
  <table class="adm-table">
    <thead><tr><th>Date</th><th>Heure</th><th>Personne</th><th>Entreprise</th><th>Contact</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $b): ?>
      <tr>
        <td><?= fr_d($b['slot_date']) ?></td>
        <td><?= h($b['slot_time']) ?></td>
        <td><?= h($b['firstname'] . ' ' . $b['lastname']) ?></td>
        <td><?= h($b['company']) ?></td>
        <td><a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></td>
        <td class="adm-actions">
          <form method="post" onsubmit="return confirm('Marquer comme fait ?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="done_booking"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn-ghost" type="submit">Fait</button>
          </form>
          <form method="post" onsubmit="return confirm('Annuler cette reservation ?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cancel_booking"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn-danger" type="submit">Annuler</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>Bloquer un jour (indisponibilite)</h3>
  <form method="post" class="adm-inline-form">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="block_date">
    <input type="date" name="date" required>
    <button class="btn-dark" type="submit">Bloquer ce jour</button>
  </form>
  <?php if ($blocked): ?>
    <div class="adm-chips">
      <?php foreach ($blocked as $d): ?>
        <span class="adm-chip"><?= fr_d($d) ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="unblock_date"><input type="hidden" name="date" value="<?= h($d) ?>"><button type="submit" aria-label="Debloquer">&times;</button></form>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <details class="adm-details">
    <summary>Historique des reservations (<?= count($recent) ?>)</summary>
    <table class="adm-table">
      <thead><tr><th>Recu le</th><th>Creneau</th><th>Personne</th><th>Entreprise</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr class="status-<?= h($b['status']) ?>">
          <td><?= fr_dt($b['created_at']) ?></td>
          <td><?= fr_d($b['slot_date']) ?> · <?= h($b['slot_time']) ?></td>
          <td><?= h($b['firstname'] . ' ' . $b['lastname']) ?></td>
          <td><?= h($b['company']) ?></td>
          <td><?= h($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
</section>

<!-- Conversations -->
<section id="conversations" class="adm-section">
  <h2>30 dernieres conversations</h2>
  <?php if (!$convs): ?>
    <p class="adm-empty">Aucune conversation enregistree pour l'instant.</p>
  <?php else: foreach ($convs as $c): ?>
    <?php $msgStmt->execute([$c['id']]); $msgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
          $first = ''; foreach ($msgs as $m) { if ($m['role']==='user') { $first = $m['content']; break; } } ?>
    <details class="adm-conv">
      <summary>
        <span class="conv-when"><?= fr_dt($c['last_at']) ?></span>
        <span class="conv-count"><?= (int)$c['msg_count'] ?> msg</span>
        <span class="conv-preview"><?= h(mb_substr($first, 0, 90)) ?></span>
      </summary>
      <div class="conv-thread">
        <?php foreach ($msgs as $m): ?>
          <div class="conv-msg conv-<?= h($m['role']) ?>">
            <span class="conv-role"><?= $m['role']==='user' ? 'Visiteur' : 'Assistant' ?></span>
            <p><?= nl2br(h($m['content'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; endif; ?>
</section>

<!-- Stats -->
<section id="stats" class="adm-section">
  <h2>Stats site &amp; blog</h2>

  <h3>Pages vues (14 derniers jours)</h3>
  <?php if (!$daily): ?>
    <p class="adm-empty">Pas encore de donnees. Le suivi demarre une fois le site en ligne (et le consentement cookies accepte).</p>
  <?php else: ?>
  <div class="adm-bars">
    <?php
      for ($i = 13; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', strtotime("-$i days"));
        $c = $daily[$day] ?? 0;
        $hgt = max(2, round(($c / $maxDaily) * 100));
        echo '<div class="bar" title="' . h($day) . ' : ' . $c . '"><span style="height:' . $hgt . '%"></span><small>' . substr($day, 8, 2) . '</small></div>';
      }
    ?>
  </div>
  <?php endif; ?>

  <div class="adm-cols">
    <div>
      <h3>Articles de blog les plus lus (30 j)</h3>
      <?php if (!$topBlog): ?><p class="adm-empty">Aucune vue d'article pour l'instant.</p><?php else: ?>
      <ol class="adm-rank">
        <?php foreach ($topBlog as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
    <div>
      <h3>Pages les plus vues (30 j)</h3>
      <?php if (!$topPages): ?><p class="adm-empty">Aucune vue pour l'instant.</p><?php else: ?>
      <ol class="adm-rank">
        <?php foreach ($topPages as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </div>
</section>

</main>
</body>
</html>

<?php
require __DIR__ . '/boot.php';

$d30 = gmdate('Y-m-d H:i:s', strtotime('-29 days'));
$since = gmdate('Y-m-d', strtotime('-29 days'));

$pv = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY d");
$pv->execute([$since . ' 00:00:00']); $pvMap = $pv->fetchAll(PDO::FETCH_KEY_PAIR);
$bk = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM bookings WHERE created_at >= ? GROUP BY d");
$bk->execute([$since . ' 00:00:00']); $bkMap = $bk->fetchAll(PDO::FETCH_KEY_PAIR);

$labels = []; $views = []; $books = [];
for ($i = 29; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', strtotime("-$i days"));
    $labels[] = substr($day, 8, 2) . '.' . substr($day, 5, 2);
    $views[] = (int)($pvMap[$day] ?? 0);
    $books[] = (int)($bkMap[$day] ?? 0);
}

$topPages = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY path ORDER BY c DESC LIMIT 12");
$topPages->execute([$d30]); $topPages = $topPages->fetchAll(PDO::FETCH_ASSOC);
$topBlog = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? AND path LIKE '/blog/%' GROUP BY path ORDER BY c DESC LIMIT 12");
$topBlog->execute([$d30]); $topBlog = $topBlog->fetchAll(PDO::FETCH_ASSOC);

$totViews = array_sum($views); $totBooks = array_sum($books);

admin_header('stats', 'Stats site & blog');
flash_render();
?>
<div class="adm-kpis" style="margin-bottom:1.4rem">
  <div class="kpi"><span class="kpi-num"><?= $totViews ?></span><span class="kpi-lbl">Pages vues (30 j)</span></div>
  <div class="kpi"><span class="kpi-num"><?= $totBooks ?></span><span class="kpi-lbl">Reservations recues (30 j)</span></div>
  <div class="kpi"><span class="kpi-num"><?= count($topBlog) ?></span><span class="kpi-lbl">Articles consultes (30 j)</span></div>
</div>

<div class="adm-block">
  <h2>Pages vues (30 derniers jours)</h2>
  <div class="chart-wrap"><canvas id="chartViews"></canvas></div>
</div>

<div class="adm-block">
  <h2>Reservations recues (30 derniers jours)</h2>
  <div class="chart-wrap"><canvas id="chartBooks"></canvas></div>
</div>

<div class="adm-block adm-cols">
  <div>
    <h2>Articles de blog les plus lus</h2>
    <?php if (!$topBlog): ?><p class="adm-empty">Aucune vue d'article (30 j).</p><?php else: ?>
    <ol class="adm-rank">
      <?php foreach ($topBlog as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
  <div>
    <h2>Pages les plus vues</h2>
    <?php if (!$topPages): ?><p class="adm-empty">Aucune vue (30 j). Le suivi demarre une fois en ligne et le consentement accepte.</p><?php else: ?>
    <ol class="adm-rank">
      <?php foreach ($topPages as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
</div>
<?php
$lbl = json_encode($labels);
$scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
. '<script>(function(){if(!window.Chart)return;var L=' . $lbl . ';'
. 'var o={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{ticks:{maxTicksLimit:10}}}};'
. 'var a=document.getElementById("chartViews");if(a)new Chart(a,{type:"line",data:{labels:L,datasets:[{data:' . json_encode($views) . ',borderColor:"#8b5cf6",backgroundColor:"rgba(139,92,246,.12)",fill:true,tension:.35,borderWidth:2,pointRadius:1}]},options:o});'
. 'var b=document.getElementById("chartBooks");if(b)new Chart(b,{type:"bar",data:{labels:L,datasets:[{data:' . json_encode($books) . ',backgroundColor:"#d97706",borderRadius:4}]},options:o});})();</script>';
admin_footer($scripts);

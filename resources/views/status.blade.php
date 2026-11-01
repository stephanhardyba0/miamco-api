<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>État des services — Miam & Co API v{{ $version }}</title>
<style>
:root{--o:#8A5A30;--o2:#A06B3F;--ink:#2A2320;--ink2:#6E6055;--canvas:#F7F4EF;--panel:#FFFFFF;--hair:rgba(42,35,32,.10)}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",system-ui,sans-serif;background:var(--canvas);color:var(--ink);min-height:100vh;font-optical-sizing:auto}
header{background:linear-gradient(90deg,#FFFFFF 0%,#F3E9DD 100%);border-bottom:3px solid transparent;border-image:linear-gradient(90deg,#6B3F23,#A06B3F,#DABB94) 1;padding:22px 28px;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:10px}
h1{font-size:22px;font-weight:700;letter-spacing:-.02em}
h1 span{color:var(--o)}
.v{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--o);font-size:14px;background:rgba(138,90,48,.10);border:1px solid rgba(138,90,48,.30);padding:4px 10px;border-radius:8px}
.live{color:#1F9D55;font-size:13px}
main{max-width:960px;margin:0 auto;padding:28px 20px 48px}
.banner{display:flex;align-items:center;gap:12px;padding:16px 18px;border-radius:14px;margin-bottom:24px;font-weight:650;font-size:16px}
.ok{background:rgba(31,157,85,.10);border:1px solid rgba(31,157,85,.35);color:#157A3F}
.ko{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.35);color:#B91C1C}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.dot.ok{background:#1F9D55;box-shadow:0 0 10px rgba(31,157,85,.55)}
.dot.ko{background:#DC2626;box-shadow:0 0 10px rgba(220,38,38,.55)}
h2{font-size:14px;font-weight:650;color:var(--ink2);margin:26px 0 12px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.card{background:var(--panel);border:1px solid var(--hair);border-radius:14px;padding:16px}
.card .name{display:flex;align-items:center;gap:9px;font-weight:650;font-size:14.5px;margin-bottom:6px}
.card .detail{color:var(--ink2);font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
footer{color:var(--ink2);font-size:12.5px;text-align:center;padding:20px;border-top:1px solid var(--hair)}
footer b{color:var(--ink)}
#age{color:var(--o)}
@media (prefers-reduced-motion:no-preference){.dot.ok{animation:pulse 2s ease-in-out infinite}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}
</style>
</head>
<body>
<header>
  <h1><span>Miam &amp; Co</span> — État des services</h1>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="live">● temps réel — actualisation 30 s</span>
    <span class="v">API v{{ $version }}</span>
  </div>
</header>
<main>
  <div class="banner {{ $status === 'operational' ? 'ok' : 'ko' }}">
    <span class="dot {{ $status === 'operational' ? 'ok' : 'ko' }}"></span>
    {{ $status === 'operational' ? 'Tous les services opérationnels' : 'Degrade — un ou plusieurs services nécessitent une attention' }}
    <span style="margin-left:auto;font-weight:400;font-size:13px;opacity:.85">
      Mis à jour <span id="age">à l'instant</span>
    </span>
  </div>

  <h2>Services</h2>
  <div class="grid">
    @foreach ($checks as $name => $check)
    <div class="card">
      <div class="name"><span class="dot {{ $check['ok'] ? 'ok' : 'ko' }}"></span>{{ $name }}</div>
      <div class="detail">{{ $check['detail'] }}</div>
    </div>
    @endforeach
  </div>

  <h2>Modules — volumes en temps réel</h2>
  <div class="grid">
    @foreach ($modules as $name => $check)
    <div class="card">
      <div class="name"><span class="dot {{ $check['ok'] ? 'ok' : 'ko' }}"></span>{{ $name }}</div>
      <div class="detail">{{ $check['detail'] }}</div>
    </div>
    @endforeach
  </div>

  <h2>Environnement</h2>
  <div class="grid">
    <div class="card"><div class="name">PHP</div><div class="detail">{{ $php }}</div></div>
    <div class="card"><div class="name">Laravel</div><div class="detail">{{ $laravel }} · {{ $environment }}</div></div>
    <div class="card"><div class="name">Requête</div><div class="detail">{{ $generated_in_ms }} ms</div></div>
  </div>

  <p style="margin-top:22px"><a href="/docs" style="color:#FF7A3D;text-decoration:none;font-size:13px">Documentation de l'API →</a>
  &nbsp;·&nbsp; <a href="/?format=json" style="color:#8FA6B3;text-decoration:none;font-size:13px">JSON</a></p>
</main>
<footer><b>Miam & Co</b> — Restaurant · Bar · Congo Brazzaville · v{{ $version }}</footer>
<script>
(function(){
  var t0 = Date.now();
  setInterval(function(){
    var el = document.getElementById('age');
    if (!el) return;
    var s = Math.round((Date.now() - t0) / 1000);
    el.textContent = s < 5 ? "à l'instant" : (s < 60 ? 'il y a ' + s + ' s' : 'il y a ' + Math.floor(s/60) + ' min');
  }, 1000);
})();
</script>
</body>
</html>

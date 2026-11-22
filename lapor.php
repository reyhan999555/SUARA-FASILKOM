<?php
/* Ajukan keluhan: formulir 4 langkah (Keluhan → Kategori → Lokasi & foto → Kirim). */
require __DIR__ . '/app.php';
require_login();
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$me = user();
$rooms = ruangan_list();
$roomIds = array_column($rooms, 'id');
if (is_admin()) { flash('Akun admin tidak mengajukan keluhan. Gunakan akun pengguna untuk melapor.', 'err'); go('index.php?hal=papan'); }
$v = ['judul' => mb_substr(trim($_GET['judul'] ?? ''), 0, 90), 'isi' => '', 'kategori' => '', 'kategori_lainnya' => '', 'ruangan_id' => 0, 'anonim' => 1];
if ($v['judul'] !== '') $v['judul'] = mb_strtoupper(mb_substr($v['judul'], 0, 1)) . mb_substr($v['judul'], 1);
$err = ''; $errStep = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $v = ['judul' => trim($_POST['judul'] ?? ''), 'isi' => trim($_POST['isi'] ?? ''), 'kategori' => $_POST['kategori'] ?? '',
        'kategori_lainnya' => trim($_POST['kategori_lainnya'] ?? ''), 'ruangan_id' => (int)($_POST['ruangan_id'] ?? 0),
        'anonim' => (($_POST['anonim'] ?? '1') === '1') ? 1 : 0];
  if (mb_strlen($v['judul']) < 8 || mb_strlen($v['judul']) > 90) { $err = 'Judul 8–90 karakter agar mudah dipahami tim verifikasi.'; $errStep = 0; }
  elseif (mb_strlen($v['isi']) < 20) { $err = 'Uraian minimal 20 karakter. Tambahkan sejak kapan dan dampaknya.'; $errStep = 0; }
  elseif (!isset(KATEGORI[$v['kategori']])) { $err = 'Pilih salah satu kategori.'; $errStep = 1; }
  elseif ($v['kategori'] === 'Lainnya' && mb_strlen($v['kategori_lainnya']) < 3) { $err = 'Jelaskan kategori atau jenis keluhan lainnya.'; $errStep = 1; }
  elseif (!in_array($v['ruangan_id'], array_map('intval', $roomIds), true)) { $err = 'Pilih lokasi ruangan.'; $errStep = 2; }
  else {
    $isiSimpan = $v['isi'] . ($v['kategori'] === 'Lainnya' ? "\n\nDetail kategori lainnya: " . $v['kategori_lainnya'] : '');
    $foto = simpan_foto('foto', $fErr);
    if ($fErr) { $err = $fErr; $errStep = 2; }
    else {
      do { $tiket = tiket_baru(); } while (q('SELECT 1 FROM laporan WHERE tiket = ?', [$tiket])->fetchColumn());
      if ($v['anonim']) {
        q('INSERT INTO laporan (tiket, user_id, owner_hash, judul, isi, kategori, ruangan_id, foto, anonim, status, dibuat) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, "menunggu", NOW())',
          [$tiket, anon_owner_hash((int)$me['id']), $v['judul'], $isiSimpan, $v['kategori'], $v['ruangan_id'], $foto]);
      } else {
        q('INSERT INTO laporan (tiket, user_id, owner_hash, judul, isi, kategori, ruangan_id, foto, anonim, status, dibuat) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, "menunggu", NOW())',
          [$tiket, $me['id'], $v['judul'], $isiSimpan, $v['kategori'], $v['ruangan_id'], $foto]);
      }
      tambah_riwayat((int)db()->lastInsertId(), 'Sistem', 'Laporan diterima' . ($v['anonim'] ? ' (anonim untuk publik).' : '.'));
      flash("Laporan terkirim dengan nomor $tiket.");
      $tujuan = 'index.php?tiket=' . urlencode($tiket);
      if ($embed) { echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Laporan terkirim</title><script>window.top.location.href=' . json_encode($tujuan) . ';</script></html>'; exit; }
      go($tujuan);
    }
  }
}

/* Laporan yang sedang ditangani, untuk peringatan "laporan serupa sudah ada". */
$open = q("SELECT l.tiket, l.judul, r.nama ruang, (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id) dukung,
           (l.user_id = ? OR l.owner_hash = ? OR EXISTS (SELECT 1 FROM dukungan d WHERE d.laporan_id = l.id AND d.user_id = ?)) saya
           FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id
           WHERE l.status IN ('verifikasi','diproses') AND l.tersembunyi = 0 ORDER BY l.dibuat DESC LIMIT 200", [$me['id'], anon_owner_hash((int)$me['id']), $me['id']])->fetchAll();
$steps = ['Keluhan', 'Kategori', 'Lokasi & foto', 'Kirim'];

page_head('Ajukan keluhan', 'lapor.php');
?>
<main class="wrap">
  <div class="page-head"><div><span class="eyebrow">Ajukan keluhan</span><h1>Sampaikan keluhan Anda</h1><p>Isi dalam 4 langkah. Laporan diverifikasi maksimal <?= VERIF_DAYS ?> hari kerja sebelum tampil di papan publik.</p></div></div>
  <div class="grid2">
    <section class="panel complaint-panel">
      <ol class="wz-steps" id="wzSteps" aria-label="Langkah pengajuan"><?php foreach ($steps as $i => $s): ?><li class="<?= $i === $errStep ? 'on' : ($i < $errStep ? 'done' : '') ?>"><span><?= $i + 1 ?></span><?= e($s) ?></li><?php endforeach; ?></ol>
      <form class="stack" id="lapor" method="post" enctype="multipart/form-data" novalidate data-step="<?= $errStep ?>">
        <?= csrf_field() ?>
        <section class="wz" data-s="0">
          <h2 style="font-size:18px">Apa yang ingin Anda keluhkan?</h2>
          <label for="judul">Judul singkat<input type="text" id="judul" name="judul" maxlength="90" value="<?= e($v['judul']) ?>" placeholder="Contoh: Proyektor tidak menyala" required></label>
          <div id="dupBox"></div>
          <label for="isi">Uraian <span class="h">Apa yang terjadi, sejak kapan, dan dampaknya. Jangan cantumkan nomor HP atau data pribadi orang lain.</span><textarea id="isi" name="isi" required><?= e($v['isi']) ?></textarea></label>
        </section>
        <section class="wz" data-s="1">
          <h2 style="font-size:18px">Termasuk kategori apa?</h2>
          <label for="kategori">Pilih kategori<select id="kategori" name="kategori" required><option value="">Pilih kategori keluhan</option><?php foreach (KATEGORI as $k => $u): ?><option value="<?= e($k) ?>" <?= $v['kategori'] === $k ? 'selected' : '' ?>><?= e($k) ?> — <?= e($u) ?></option><?php endforeach; ?></select></label>
          <label for="kategori_lainnya" id="kategoriLainnyaWrap" <?= $v['kategori'] === 'Lainnya' ? '' : 'hidden' ?>>Jelaskan keluhan lainnya<textarea id="kategori_lainnya" name="kategori_lainnya" rows="3" placeholder="Contoh: fasilitas parkir, aksesibilitas, atau kebutuhan lain di luar kategori yang tersedia."><?= e($v['kategori_lainnya']) ?></textarea><span class="h">Tuliskan jenis masalah atau layanan yang belum tercantum di pilihan kategori.</span></label>
        </section>
        <section class="wz" data-s="2">
          <h2 style="font-size:18px">Di mana lokasinya?</h2>
          <label for="ruangan_id">Lokasi<select id="ruangan_id" name="ruangan_id"><option value="">Pilih lokasi</option><?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>" <?= $v['ruangan_id'] == $r['id'] ? 'selected' : '' ?>><?= e($r['nama']) ?></option><?php endforeach; ?></select></label>
          <div><div style="font-size:13.5px;font-weight:700;margin-bottom:6px">Foto pendukung <span class="h">(opsional)</span></div>
            <label class="drop" id="drop" for="foto"><input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
              <span>Pilih atau seret foto ke sini<small>JPG, PNG, atau WebP · maks 5 MB · hindari wajah orang lain</small></span></label>
            <div class="preview" id="fotoPrev" hidden><img alt="Pratinjau foto"><button type="button" id="fotoDel">Hapus foto</button></div></div>
        </section>
        <section class="wz" data-s="3">
          <h2 style="font-size:18px">Periksa lagi sebelum dikirim</h2>
          <dl class="review" id="review"></dl>
          <div class="sender-choice" role="group" aria-labelledby="senderChoiceTitle">
            <b id="senderChoiceTitle">Tampilkan identitas pengirim sebagai</b>
            <label class="sender-option"><input type="radio" name="anonim" value="1" <?= $v['anonim'] ? 'checked' : '' ?>><span><strong>Anonim</strong><small>Identitas akun tidak ditautkan ke laporan dan tidak dapat dilihat admin.</small></span></label>
            <label class="sender-option"><input type="radio" name="anonim" value="0" <?= !$v['anonim'] ? 'checked' : '' ?>><span><strong>Publik</strong><small>Nama akun ditampilkan pada laporan yang sudah dipublikasikan.</small></span></label>
          </div>
        </section>
        <div class="err" id="err" aria-live="polite"><?= e($err) ?></div>
        <div class="wz-nav">
          <button class="btn" type="button" id="back">‹ Kembali</button><span class="grow"></span>
          <button class="btn primary" type="button" id="next">Lanjut ›</button>
          <button class="btn primary" type="submit" id="send">Kirim laporan</button>
        </div>
      </form>
    </section>
    <aside style="display:grid;gap:16px">
      <section class="emerg"><h2>Hal darurat?</h2><p>Kekerasan, pelecehan, atau ancaman keamanan jangan dilaporkan lewat formulir ini. Hubungi Satgas PPKS kampus atau pos keamanan terdekat.</p></section>
      <section class="panel"><h2>Tips laporan yang cepat ditangani</h2><ul style="margin:10px 0 0;padding-left:18px;display:grid;gap:6px">
        <li>Satu laporan untuk satu masalah.</li><li>Sebutkan sejak kapan dan seberapa sering terjadi.</li><li>Lampirkan foto bila masalahnya terlihat.</li><li>Cek papan dulu. Jika sudah ada, beri dukungan saja.</li></ul></section>
    </aside>
  </div>
</main>
<script>
(function(){
  var OPEN = <?= json_encode($open, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var CSRF = <?= json_encode(csrf()) ?>;
  var f=document.getElementById('lapor'), steps=[].slice.call(document.querySelectorAll('#wzSteps li')), secs=[].slice.call(f.querySelectorAll('.wz')),
      back=document.getElementById('back'), next=document.getElementById('next'), send=document.getElementById('send'), err=document.getElementById('err');
  var cur=+f.dataset.step||0;
  function resizeEmbedded(){ if(window.parent!==window){ requestAnimationFrame(function(){ var h=Math.max(document.body.scrollHeight,document.documentElement.scrollHeight); window.parent.postMessage({type:'sf-complaint-resize',height:h},'*'); }); } }
  function esc(s){ return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function show(n){
    cur=Math.max(0,Math.min(3,n));
    secs.forEach(function(s){
      var active = +s.dataset.s === cur;
      s.hidden = !active;
      s.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    steps.forEach(function(li,i){ li.className=(i<cur?'done ':'')+(i===cur?'on':''); li.querySelector('span').textContent=i<cur?'✓':i+1; if(i===cur) li.setAttribute('aria-current','step'); else li.removeAttribute('aria-current'); });
    back.hidden=cur===0; next.hidden=cur===3; send.hidden=cur!==3;
    if(cur===3){
      var k=document.getElementById('kategori'), r=document.getElementById('ruangan_id'), ft=document.getElementById('foto').files[0], sender=f.querySelector('input[name=anonim]:checked');
      var detail=k.value==='Lainnya' ? ' — '+document.getElementById('kategori_lainnya').value.trim() : '';
      document.getElementById('review').innerHTML='<dt>Judul</dt><dd>'+esc(f.judul.value.trim())+'</dd><dt>Uraian</dt><dd>'+esc(f.isi.value.trim())+'</dd><dt>Kategori</dt><dd>'+esc(k.value||'-')+esc(detail)+'</dd><dt>Lokasi</dt><dd>'+esc(r.options[r.selectedIndex].text)+'</dd><dt>Pengirim</dt><dd>'+(sender&&sender.value==='0'?'Publik':'Anonim')+'</dd><dt>Foto</dt><dd>'+(ft?esc(ft.name):'Tanpa foto')+'</dd>';
    }
    resizeEmbedded();
  }
  function check(n){
    var m='';
    if(n===0){ if(f.judul.value.trim().length<8) m='Judul minimal 8 karakter agar mudah dipahami tim verifikasi.'; else if(f.isi.value.trim().length<20) m='Uraian minimal 20 karakter. Tambahkan sejak kapan dan dampaknya.'; }
    if(n===1){ var k=document.getElementById('kategori'); if(!k.value) m='Pilih salah satu kategori.'; else if(k.value==='Lainnya' && document.getElementById('kategori_lainnya').value.trim().length<3) m='Jelaskan kategori atau jenis keluhan lainnya.'; }
    if(n===2){ if(!f.ruangan_id.value) m='Pilih lokasi agar petugas bisa menemukan masalahnya.'; var ft=document.getElementById('foto').files[0]; if(!m && ft && ft.size>5*1024*1024) m='Ukuran foto melebihi 5 MB.'; }
    err.textContent=m; return !m;
  }
  var kategori=document.getElementById('kategori'), kategoriWrap=document.getElementById('kategoriLainnyaWrap');
  function toggleKategoriLainnya(){ var other=kategori.value==='Lainnya'; kategoriWrap.hidden=!other; document.getElementById('kategori_lainnya').required=other; if(other) document.getElementById('kategori_lainnya').focus({preventScroll:true}); resizeEmbedded(); }
  kategori.addEventListener('change',toggleKategoriLainnya); toggleKategoriLainnya();
  next.addEventListener('click',function(){ if(check(cur)) show(cur+1); });
  back.addEventListener('click',function(){ err.textContent=''; show(cur-1); });
  f.addEventListener('submit',function(e){ if(cur<3){ e.preventDefault(); if(check(cur)) show(cur+1); return; } for(var i=0;i<3;i++){ if(!check(i)){ e.preventDefault(); show(i); return; } } send.disabled=true; send.textContent='Mengirim…'; });
  var foto=document.getElementById('foto'), drop=document.getElementById('drop'), prev=document.getElementById('fotoPrev');
  function showFoto(){ var ft=foto.files[0];
    if(ft && !/^image\/(jpeg|png|webp)$/.test(ft.type)){ err.textContent='File harus berupa foto JPG, PNG, atau WebP.'; foto.value=''; ft=null; }
    if(ft && ft.size>5*1024*1024){ err.textContent='Ukuran foto melebihi 5 MB.'; foto.value=''; ft=null; }
    if(ft){ err.textContent=''; prev.querySelector('img').src=URL.createObjectURL(ft); prev.hidden=false; drop.hidden=true; } else { prev.hidden=true; drop.hidden=false; } }
  foto.addEventListener('change',showFoto);
  document.getElementById('fotoDel').addEventListener('click',function(){ foto.value=''; showFoto(); });
  ['dragover','dragenter'].forEach(function(ev){ drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave','drop'].forEach(function(ev){ drop.addEventListener(ev,function(){ drop.classList.remove('over'); }); });
  drop.addEventListener('drop',function(e){ e.preventDefault(); if(e.dataTransfer.files.length){ try{ foto.files=e.dataTransfer.files; }catch(x){} showFoto(); } });
  /* Peringatan laporan serupa */
  var STOP={yang:1,dan:1,di:1,ke:1,dari:1,tidak:1,sudah:1,belum:1,ada:1,untuk:1,dengan:1,pada:1,sejak:1,ini:1,itu:1};
  function toks(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9 ]/g,' ').split(/\s+/).filter(function(t){return t.length>2&&!STOP[t];}); }
  function dup(){
    var a=toks(f.judul.value), box=document.getElementById('dupBox'); if(a.length<2){ box.innerHTML=''; return; }
    var hits=OPEN.map(function(o){ var b=toks(o.judul+' '+(o.ruang||'')), n=a.filter(function(t){return b.indexOf(t)>-1;}).length; return {o:o,n:n}; }).filter(function(x){return x.n>=2;}).sort(function(x,y){return y.n-x.n;}).slice(0,3);
    box.innerHTML = hits.length ? '<div class="box warn"><h3>Laporan serupa sudah ada</h3><span class="h">Jika masalahnya sama, dukung laporan yang sudah ada supaya penanganannya lebih cepat.</span><div class="dup">'+hits.map(function(x){
      return '<div class="dup-item"><a href="index.php?tiket='+encodeURIComponent(x.o.tiket)+'"><b>'+esc(x.o.judul)+'</b><br><span class="h">'+esc(x.o.tiket)+' · '+esc(x.o.ruang||'')+' · ▲ '+(+x.o.dukung)+'</span></a>'
        +(x.o.saya?'<a class="btn sm" href="index.php?tiket='+encodeURIComponent(x.o.tiket)+'">Lihat</a>':'<button class="btn sm" type="button" data-dukung="'+esc(x.o.tiket)+'">Dukung</button>')+'</div>';
    }).join('')+'</div></div>' : '';
  }
  f.judul.addEventListener('input',dup);
  /* Tombol "Dukung" di peringatan laporan serupa: kirim dukungan lalu buka laporan tersebut */
  document.getElementById('dupBox').addEventListener('click',function(e){
    var b=e.target.closest('[data-dukung]'); if(!b) return;
    var t=b.getAttribute('data-dukung'), g=document.createElement('form'); g.method='post'; g.action='index.php';
    [['csrf',CSRF],['aksi','dukung'],['tiket',t],['kembali','index.php?tiket='+encodeURIComponent(t)]].forEach(function(kv){ var i=document.createElement('input'); i.type='hidden'; i.name=kv[0]; i.value=kv[1]; g.appendChild(i); });
    document.body.appendChild(g); g.submit();
  });
  show(cur); dup();
  if(window.parent!==window && 'ResizeObserver' in window){ var ro=new ResizeObserver(resizeEmbedded); ro.observe(document.body); ro.observe(document.documentElement); }
  window.addEventListener('load',resizeEmbedded); setTimeout(resizeEmbedded,80); setTimeout(resizeEmbedded,350);
})();
</script>
<?php page_foot();

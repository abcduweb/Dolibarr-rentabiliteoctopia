<?php
/**
 * Simulateur de prix de vente - Aide a la decision tarifaire
 *
 * Deux modes de calcul bidirectionnels :
 *  A. COUT -> PRIX : je connais mon cout d'achat, quel prix pour X% de marge ?
 *  B. PRIX -> MARGE : je connais le prix (concurrent), quelle est ma marge reelle ?
 *
 * Composantes selectionnables : cout achat, commission Cdiscount, frais de port,
 * TVA, frais d'expedition/packaging, autres frais variables.
 *
 * Tout est calcule cote client (JS) en temps reel, avec pre-remplissage depuis
 * les categories existantes (taux commission) et les produits Dolibarr (cout_price).
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/PricingEngine.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$params      = rentabiliteoctopia_get_params($db);
$seuilMarge  = isset($params['seuil_marge_pct']) ? (float)$params['seuil_marge_pct'] : 15;
$tauxRetour  = isset($params['taux_retour_pct']) ? (float)$params['taux_retour_pct'] : 3;
$coutRetour  = isset($params['cout_retour'])     ? (float)$params['cout_retour']     : 2.50;

// Categories pour pre-remplir les taux de commission
$categories = rentabiliteoctopia_get_categories($db);
$catsJson = array();
foreach ($categories as $c) {
    $catsJson[] = array(
        'id'    => (int)$c['rowid'],
        'label' => $c['label'],
        'pct'   => (float)$c['commission_pct'],
    );
}

// Produits Dolibarr (pour pre-remplir cout d'achat depuis une ref existante)
$produitsJson = array();
$sqlP = "SELECT rowid, ref, label, cost_price, price
         FROM ".MAIN_DB_PREFIX."product
         WHERE entity IN (0,".((int)$conf->entity).") AND tosell = 1
         ORDER BY ref LIMIT 1000";
$rP = $db->query($sqlP);
while ($rP && $o = $db->fetch_object($rP)) {
    $produitsJson[] = array(
        'ref'   => $o->ref,
        'label' => $o->label,
        'cost'  => (float)$o->cost_price,
        'price' => (float)$o->price,
    );
}

llxHeader('', 'Simulateur de prix');
print load_fiche_titre('Simulateur de prix de vente', '', 'fa-calculator');

// On injecte la config PHP dans le JS
$configJs = json_encode(array(
    'seuilMarge' => $seuilMarge,
    'tauxRetour' => $tauxRetour,
    'coutRetour' => $coutRetour,
    'cats'       => $catsJson,
    'produits'   => $produitsJson,
    'tvaDefaut'  => 20,
));
?>

<style>
.sim-wrap { max-width:1100px; }
.sim-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
@media(max-width:800px){ .sim-grid{ grid-template-columns:1fr; } }
.sim-card { background:#fff; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
.sim-card h3 { margin-top:0; padding-bottom:10px; border-bottom:2px solid #f0f0f0; }
.sim-mode-tabs { display:flex; gap:8px; margin-bottom:20px; }
.sim-tab { flex:1; padding:12px; text-align:center; background:#ecf0f1; border-radius:6px; cursor:pointer; font-weight:bold; border:2px solid transparent; transition:all 0.15s; }
.sim-tab.active { background:#667eea; color:#fff; border-color:#5568d3; }
.sim-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f5f5f5; }
.sim-row label { font-size:13px; color:#444; flex:1; }
.sim-row input[type=number], .sim-row select { width:110px; padding:6px 8px; border:1px solid #ddd; border-radius:4px; text-align:right; font-size:14px; }
.sim-row select { text-align:left; width:200px; }
.sim-toggle { display:flex; align-items:center; gap:6px; }
.sim-component-row { padding:10px; border-radius:6px; margin-bottom:6px; background:#fafafa; }
.sim-component-row.disabled { opacity:0.4; }
.sim-result-big { font-size:38px; font-weight:bold; margin:10px 0; }
.sim-result-line { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #f0f0f0; }
.sim-result-line.total { font-weight:bold; font-size:16px; border-top:2px solid #333; border-bottom:none; padding-top:10px; margin-top:6px; }
.sim-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:bold; }
.sim-slider-wrap { margin:14px 0; }
.sim-slider-wrap input[type=range] { width:100%; }
.sim-presets { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.sim-preset-btn { padding:4px 12px; background:#ecf0f1; border:none; border-radius:4px; cursor:pointer; font-size:12px; }
.sim-preset-btn:hover { background:#667eea; color:#fff; }
.sim-bar { height:28px; border-radius:4px; display:flex; overflow:hidden; margin:14px 0; font-size:11px; color:#fff; font-weight:bold; }
.sim-bar-seg { display:flex; align-items:center; justify-content:center; white-space:nowrap; overflow:hidden; }
</style>

<div class="sim-wrap">

  <div class="sim-mode-tabs">
    <div class="sim-tab active" id="tabA" onclick="setMode('A')">Mode A : Coût → Prix de vente<br><span style="font-weight:normal;font-size:11px">Quel prix pour une marge cible ?</span></div>
    <div class="sim-tab" id="tabB" onclick="setMode('B')">Mode B : Prix → Marge réelle<br><span style="font-weight:normal;font-size:11px">Ce prix concurrent est-il rentable ?</span></div>
  </div>

  <div class="sim-grid">

    <!-- COLONNE GAUCHE : ENTREES -->
    <div class="sim-card">
      <h3>Paramètres du produit</h3>

      <!-- Pre-remplissage produit existant -->
      <div class="sim-row">
        <label>Charger un produit existant</label>
        <select id="selProduit" onchange="loadProduit()" style="width:240px">
          <option value="">— Saisie manuelle —</option>
        </select>
      </div>

      <!-- Cout d'achat -->
      <div class="sim-row">
        <label><b>Coût d'achat HT (€)</b></label>
        <input type="number" id="coutAchat" value="10.00" step="0.01" min="0" oninput="calc()">
      </div>

      <!-- Mode A: marge cible / Mode B: prix de vente -->
      <div class="sim-row" id="rowMargeCible">
        <label><b>Marge nette cible (%)</b></label>
        <input type="number" id="margeCible" value="25" step="0.5" min="0" oninput="syncSliderFromInput(); calc()">
      </div>
      <div class="sim-slider-wrap" id="sliderMargeWrap">
        <input type="range" id="sliderMarge" min="0" max="80" value="25" step="1" oninput="syncInputFromSlider(); calc()">
        <div class="sim-presets">
          <button class="sim-preset-btn" onclick="setMarge(10)">10%</button>
          <button class="sim-preset-btn" onclick="setMarge(15)">15%</button>
          <button class="sim-preset-btn" onclick="setMarge(20)">20%</button>
          <button class="sim-preset-btn" onclick="setMarge(25)">25%</button>
          <button class="sim-preset-btn" onclick="setMarge(30)">30%</button>
          <button class="sim-preset-btn" onclick="setMarge(40)">40%</button>
        </div>
      </div>

      <div class="sim-row" id="rowPrixVente" style="display:none">
        <label><b>Prix de vente TTC visé (€)</b></label>
        <input type="number" id="prixVenteTTC" value="29.99" step="0.01" min="0" oninput="calc()">
      </div>

      <h3 style="margin-top:24px;">Composantes du coût</h3>
      <p style="font-size:12px;color:#888;margin-top:0;">Cochez les coûts à intégrer dans le calcul.</p>

      <!-- Commission Cdiscount -->
      <div class="sim-component-row" id="compCommission">
        <div class="sim-toggle">
          <input type="checkbox" id="useCommission" checked onchange="toggleComp('Commission'); calc()">
          <label for="useCommission" style="flex:1"><b>Commission Cdiscount</b></label>
        </div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
          <select id="catCommission" onchange="applyCatPct(); calc()" style="flex:1">
            <option value="">Taux manuel</option>
          </select>
          <input type="number" id="commissionPct" value="10" step="0.1" min="0" max="50" style="width:80px" oninput="calc()"> %
        </div>
      </div>

      <!-- Frais de port -->
      <div class="sim-component-row" id="compPort">
        <div class="sim-toggle">
          <input type="checkbox" id="usePort" checked onchange="toggleComp('Port'); calc()">
          <label for="usePort" style="flex:1"><b>Frais de port à ta charge</b> (€)</label>
          <input type="number" id="fraisPort" value="3.50" step="0.01" min="0" style="width:80px" oninput="calc()">
        </div>
        <div style="margin-top:6px;font-size:11px;color:#888;">
          <label class="sim-toggle"><input type="checkbox" id="portRefacture" onchange="calc()"> Port refacturé au client (devient un revenu)</label>
          <input type="number" id="portClient" value="4.99" step="0.01" min="0" style="width:70px;margin-left:8px;" oninput="calc()"> € facturés
        </div>
      </div>

      <!-- Packaging -->
      <div class="sim-component-row" id="compPackaging">
        <div class="sim-toggle">
          <input type="checkbox" id="usePackaging" onchange="toggleComp('Packaging'); calc()">
          <label for="usePackaging" style="flex:1">Packaging / emballage (€)</label>
          <input type="number" id="packaging" value="0.50" step="0.01" min="0" style="width:80px" oninput="calc()">
        </div>
      </div>

      <!-- Retours -->
      <div class="sim-component-row" id="compRetours">
        <div class="sim-toggle">
          <input type="checkbox" id="useRetours" onchange="toggleComp('Retours'); calc()">
          <label for="useRetours" style="flex:1">Provision retours</label>
          <span style="font-size:12px;color:#888">auto</span>
        </div>
        <div style="margin-top:6px;font-size:11px;color:#888;">
          Taux retour <input type="number" id="retourPct" value="3" step="0.5" min="0" style="width:60px" oninput="calc()"> %
          × coût <input type="number" id="retourCout" value="2.50" step="0.01" min="0" style="width:70px" oninput="calc()"> €
        </div>
      </div>

      <!-- Autres frais variables -->
      <div class="sim-component-row" id="compAutres">
        <div class="sim-toggle">
          <input type="checkbox" id="useAutres" onchange="toggleComp('Autres'); calc()">
          <label for="useAutres" style="flex:1">Autres frais variables (€)</label>
          <input type="number" id="autresFrais" value="0.00" step="0.01" min="0" style="width:80px" oninput="calc()">
        </div>
      </div>

      <!-- TVA -->
      <div class="sim-component-row" id="compTVA">
        <div class="sim-toggle">
          <input type="checkbox" id="useTVA" checked onchange="toggleComp('TVA'); calc()">
          <label for="useTVA" style="flex:1"><b>TVA</b> (pour affichage prix TTC)</label>
          <input type="number" id="tvaPct" value="20" step="0.1" min="0" style="width:80px" oninput="calc()"> %
        </div>
      </div>

    </div>

    <!-- COLONNE DROITE : RESULTATS -->
    <div class="sim-card" id="resultCard">
      <h3>Résultat</h3>

      <div id="resultMain"></div>

      <div class="sim-bar" id="costBar"></div>
      <div style="font-size:11px;color:#888;text-align:center;margin-top:-8px;">Répartition du prix de vente HT</div>

      <div id="resultDetail" style="margin-top:20px;"></div>

      <div id="resultVerdict" style="margin-top:20px;"></div>
    </div>

  </div>

  <!-- TABLEAU COMPARATIF MULTI-MARGES (mode A uniquement) -->
  <div class="sim-card" id="comparTable" style="margin-bottom:20px;">
    <h3>Tableau comparatif selon la marge cible</h3>
    <table class="noborder centpercent">
      <tr class="liste_titre">
        <th>Marge nette visée</th>
        <th class="right">Prix de vente HT</th>
        <th class="right">Prix de vente TTC</th>
        <th class="right">Marge nette (€)</th>
        <th class="right">Coefficient</th>
      </tr>
      <tbody id="comparBody"></tbody>
    </table>
  </div>

</div>

<script>
const CFG = <?php echo $configJs; ?>;

// === Formule centralisee (injectee depuis PricingEngine) ===
<?php echo PricingEngine::getJsFormula(); ?>
let MODE = 'A';

// Remplir les selects
window.addEventListener('DOMContentLoaded', function() {
    const selCat = document.getElementById('catCommission');
    const selCatMain = document.getElementById('catCommission');
    CFG.cats.forEach(c => {
        const o = document.createElement('option');
        o.value = c.pct;
        o.textContent = c.label + ' (' + c.pct + '%)';
        o.dataset.label = c.label;
        selCat.appendChild(o);
    });

    const selProd = document.getElementById('selProduit');
    CFG.produits.forEach(p => {
        const o = document.createElement('option');
        o.value = p.ref;
        o.textContent = p.ref + ' — ' + (p.label || '').substring(0,40);
        o.dataset.cost = p.cost;
        o.dataset.price = p.price;
        selProd.appendChild(o);
    });

    document.getElementById('retourPct').value = CFG.tauxRetour;
    document.getElementById('retourCout').value = CFG.coutRetour;
    document.getElementById('tvaPct').value = CFG.tvaDefaut;

    calc();
});

function setMode(m) {
    MODE = m;
    document.getElementById('tabA').classList.toggle('active', m === 'A');
    document.getElementById('tabB').classList.toggle('active', m === 'B');
    document.getElementById('rowMargeCible').style.display = (m === 'A') ? 'flex' : 'none';
    document.getElementById('sliderMargeWrap').style.display = (m === 'A') ? 'block' : 'none';
    document.getElementById('rowPrixVente').style.display = (m === 'B') ? 'flex' : 'none';
    document.getElementById('comparTable').style.display = (m === 'A') ? 'block' : 'none';
    calc();
}

function setMarge(v) {
    document.getElementById('margeCible').value = v;
    document.getElementById('sliderMarge').value = v;
    calc();
}
function syncSliderFromInput() {
    document.getElementById('sliderMarge').value = document.getElementById('margeCible').value;
}
function syncInputFromSlider() {
    document.getElementById('margeCible').value = document.getElementById('sliderMarge').value;
}

function applyCatPct() {
    const sel = document.getElementById('catCommission');
    if (sel.value !== '') document.getElementById('commissionPct').value = sel.value;
}

function loadProduit() {
    const sel = document.getElementById('selProduit');
    const opt = sel.options[sel.selectedIndex];
    if (opt.value === '') return;
    const cost = parseFloat(opt.dataset.cost) || 0;
    const price = parseFloat(opt.dataset.price) || 0;
    if (cost > 0) document.getElementById('coutAchat').value = cost.toFixed(2);
    if (price > 0 && MODE === 'B') document.getElementById('prixVenteTTC').value = price.toFixed(2);
    calc();
}

function toggleComp(name) {
    const cb = document.getElementById('use' + name);
    const comp = document.getElementById('comp' + name);
    if (comp) comp.classList.toggle('disabled', !cb.checked);
}

function val(id) { return parseFloat(document.getElementById(id).value) || 0; }
function chk(id) { return document.getElementById(id).checked; }
function fmtE(n) {
    return n.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
}

/**
 * Calcul central. On raisonne en HT.
 *
 * Cout total HT par vente = cout_achat
 *                         + commission (% du prix de vente HT)
 *                         + port a charge - port refacture
 *                         + packaging + retours + autres
 *
 * En mode A (cout->prix) : on cherche PV_HT tel que
 *   marge_nette% = (PV_HT - couts) / PV_HT
 *   Or commission depend de PV_HT, donc :
 *   PV_HT - (cout_fixes + commission_pct*PV_HT) = marge% * PV_HT
 *   PV_HT * (1 - commission_pct - marge%) = cout_fixes
 *   PV_HT = cout_fixes / (1 - commission_pct - marge%)
 *
 * En mode B (prix->marge) : PV_TTC donne, on calcule PV_HT puis la marge.
 */
function computeFor(margePct, prixVenteHTknown) {
    const coutAchat = val('coutAchat');
    const commPct = chk('useCommission') ? val('commissionPct')/100 : 0;
    const portCharge = chk('usePort') ? val('fraisPort') : 0;
    const portRefac = (chk('usePort') && chk('portRefacture')) ? val('portClient') : 0;
    const packaging = chk('usePackaging') ? val('packaging') : 0;
    const retours = chk('useRetours') ? (val('retourPct')/100 * val('retourCout')) : 0;
    const autres = chk('useAutres') ? val('autresFrais') : 0;
    const tvaPct = chk('useTVA') ? val('tvaPct')/100 : 0;

    // Couts fixes (independants du prix de vente)
    // Le port refacture est un revenu -> on le soustrait des couts
    const coutsFixes = coutAchat + portCharge + packaging + retours + autres - portRefac;

    let pvHT;
    if (prixVenteHTknown !== null) {
        pvHT = prixVenteHTknown;
    } else {
        // Mode A : resoudre PV_HT
        const denom = 1 - commPct - margePct/100;
        if (denom <= 0) return null; // impossible (commission + marge >= 100%)
        pvHT = coutsFixes / denom;
    }

    const commission = commPct * pvHT;
    const margeNette = pvHT - coutsFixes - commission;
    const margePctReel = pvHT > 0 ? (margeNette / pvHT * 100) : 0;
    const pvTTC = pvHT * (1 + tvaPct);
    const coef = coutAchat > 0 ? (pvHT / coutAchat) : 0;

    return {
        coutAchat, commission, commPct, portCharge, portRefac, packaging, retours, autres,
        coutsFixes, pvHT, pvTTC, margeNette, margePctReel, coef, tvaPct,
    };
}

function calc() {
    let r;
    if (MODE === 'A') {
        r = computeFor(val('margeCible'), null);
    } else {
        const tvaPct = chk('useTVA') ? val('tvaPct')/100 : 0;
        const pvHT = val('prixVenteTTC') / (1 + tvaPct);
        r = computeFor(0, pvHT);
    }

    const main = document.getElementById('resultMain');
    const detail = document.getElementById('resultDetail');
    const verdict = document.getElementById('resultVerdict');
    const bar = document.getElementById('costBar');

    if (!r) {
        main.innerHTML = '<div style="color:#c0392b;font-weight:bold;padding:20px;text-align:center;">Impossible : commission + marge cible ≥ 100%.<br>Réduisez la marge ou la commission.</div>';
        detail.innerHTML = ''; verdict.innerHTML = ''; bar.innerHTML = '';
        return;
    }

    // Bloc principal
    if (MODE === 'A') {
        main.innerHTML =
            '<div style="text-align:center;">' +
            '<div style="font-size:13px;color:#888;text-transform:uppercase;">Prix de vente conseillé</div>' +
            '<div class="sim-result-big" style="color:#667eea;">' + fmtE(r.pvTTC) + ' <span style="font-size:16px;color:#888;">TTC</span></div>' +
            '<div style="font-size:14px;color:#666;">soit ' + fmtE(r.pvHT) + ' HT &nbsp;·&nbsp; coefficient ×' + r.coef.toFixed(2) + '</div>' +
            '</div>';
    } else {
        const col = r.margeNette >= 0 ? '#27ae60' : '#c0392b';
        main.innerHTML =
            '<div style="text-align:center;">' +
            '<div style="font-size:13px;color:#888;text-transform:uppercase;">Marge nette sur ce prix</div>' +
            '<div class="sim-result-big" style="color:' + col + ';">' + fmtE(r.margeNette) + '</div>' +
            '<div style="font-size:16px;color:' + col + ';font-weight:bold;">' + r.margePctReel.toFixed(1) + ' % du prix HT</div>' +
            '</div>';
    }

    // Barre de repartition (sur PV HT)
    const segs = [
        {label:'Achat',      val:r.coutAchat,  color:'#34495e'},
        {label:'Commission', val:r.commission, color:'#e67e22'},
        {label:'Port',       val:r.portCharge - r.portRefac, color:'#9b59b6'},
        {label:'Packaging',  val:r.packaging,  color:'#16a085'},
        {label:'Retours',    val:r.retours,    color:'#c0392b'},
        {label:'Autres',     val:r.autres,     color:'#7f8c8d'},
        {label:'Marge',      val:r.margeNette, color:'#27ae60'},
    ];
    let barHtml = '';
    segs.forEach(s => {
        if (Math.abs(s.val) < 0.001) return;
        const pct = r.pvHT > 0 ? (Math.abs(s.val) / r.pvHT * 100) : 0;
        if (pct < 0.5) return;
        barHtml += '<div class="sim-bar-seg" style="width:' + pct + '%;background:' + s.color + ';" title="' + s.label + ' : ' + fmtE(s.val) + '">' + (pct > 8 ? s.label : '') + '</div>';
    });
    bar.innerHTML = barHtml;

    // Detail lignes
    let d = '';
    d += line('Coût d\'achat HT', '-' + fmtE(r.coutAchat), '#34495e');
    if (chk('useCommission')) d += line('Commission Cdiscount (' + (r.commPct*100).toFixed(1) + '%)', '-' + fmtE(r.commission), '#e67e22');
    if (chk('usePort')) {
        d += line('Frais de port à charge', '-' + fmtE(r.portCharge), '#9b59b6');
        if (r.portRefac > 0) d += line('Port refacturé au client', '+' + fmtE(r.portRefac), '#27ae60');
    }
    if (chk('usePackaging')) d += line('Packaging', '-' + fmtE(r.packaging), '#16a085');
    if (chk('useRetours')) d += line('Provision retours', '-' + fmtE(r.retours), '#c0392b');
    if (chk('useAutres')) d += line('Autres frais', '-' + fmtE(r.autres), '#7f8c8d');
    d += '<div class="sim-result-line total"><span>Prix de vente HT</span><span>' + fmtE(r.pvHT) + '</span></div>';
    if (chk('useTVA')) d += '<div class="sim-result-line"><span>TVA (' + (r.tvaPct*100).toFixed(1) + '%)</span><span>' + fmtE(r.pvTTC - r.pvHT) + '</span></div>';
    d += '<div class="sim-result-line total"><span>Prix de vente TTC</span><span style="color:#667eea">' + fmtE(r.pvTTC) + '</span></div>';
    d += '<div class="sim-result-line total"><span>Marge nette</span><span style="color:' + (r.margeNette>=0?'#27ae60':'#c0392b') + '">' + fmtE(r.margeNette) + ' (' + r.margePctReel.toFixed(1) + '%)</span></div>';
    detail.innerHTML = d;

    // Verdict
    let vColor, vText, vIcon;
    if (r.margeNette < 0) {
        vColor = '#c0392b'; vIcon = '✗'; vText = 'PERTE — ce prix te fait perdre de l\'argent sur chaque vente.';
    } else if (r.margePctReel < CFG.seuilMarge) {
        vColor = '#e67e22'; vIcon = '⚠'; vText = 'Marge faible (' + r.margePctReel.toFixed(1) + '% < seuil ' + CFG.seuilMarge + '%). Rentable mais peu confortable.';
    } else {
        vColor = '#27ae60'; vIcon = '✓'; vText = 'Bonne rentabilité (' + r.margePctReel.toFixed(1) + '% ≥ seuil ' + CFG.seuilMarge + '%).';
    }
    verdict.innerHTML = '<div style="padding:14px;border-radius:6px;background:' + vColor + '15;border-left:4px solid ' + vColor + ';"><span style="font-size:18px;">' + vIcon + '</span> <b style="color:' + vColor + '">' + vText + '</b></div>';

    // Tableau comparatif (mode A)
    if (MODE === 'A') {
        const marges = [10, 15, 20, 25, 30, 35, 40, 50];
        let tb = '';
        marges.forEach(m => {
            const rr = computeFor(m, null);
            if (!rr) { tb += '<tr class="oddeven"><td>' + m + '%</td><td colspan="4" style="color:#c0392b">impossible</td></tr>'; return; }
            const isCurrent = (m == val('margeCible'));
            tb += '<tr class="oddeven"' + (isCurrent ? ' style="background:#667eea15;font-weight:bold;"' : '') + '>';
            tb += '<td>' + m + '%' + (isCurrent ? ' ◄' : '') + '</td>';
            tb += '<td class="right">' + fmtE(rr.pvHT) + '</td>';
            tb += '<td class="right">' + fmtE(rr.pvTTC) + '</td>';
            tb += '<td class="right" style="color:#27ae60">' + fmtE(rr.margeNette) + '</td>';
            tb += '<td class="right">×' + rr.coef.toFixed(2) + '</td>';
            tb += '</tr>';
        });
        document.getElementById('comparBody').innerHTML = tb;
    }
}

function line(label, val, color) {
    return '<div class="sim-result-line"><span style="color:' + color + '">' + label + '</span><span>' + val + '</span></div>';
}
</script>

<?php
llxFooter();
$db->close();

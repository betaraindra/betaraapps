<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- CONFIG & SETTINGS ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

// --- FILTER ---
// Default: Tahun ini (Januari s/d Desember)
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31');

// --- DATA PROCESSING ---
$report_data = [];
$totals = [
    'omzet' => 0, 'rutin' => 0, 'gaji' => 0, 'modal' => 0, 
    'material' => 0, 'taktis' => 0, 'total_keluar' => 0, 'profit' => 0
];

// Logic Loop: Iterate Month by Month based on Start and End Date
$current_ts = strtotime($start_date);
$end_ts = strtotime($end_date);

// Normalize to first of month to ensure loop doesn't skip (e.g. from Jan 31 to Mar 3)
$current_ts = strtotime(date('Y-m-01', $current_ts));

while ($current_ts <= $end_ts) {
    $date_start = date('Y-m-01', $current_ts);
    $date_end = date('Y-m-t', $current_ts);
    
    // Label Bulan (Contoh: Jan 2025)
    // Array nama bulan indo
    $month_num = date('n', $current_ts);
    $year_num = date('Y', $current_ts);
    $indo_months = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des'];
    $month_label = $indo_months[$month_num] . ' ' . $year_num;

    // 1. OMZET (Total Pemasukan)
    $omzet = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;

    // 2. BREAKDOWN PENGELUARAN
    $rutin = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND (a.code='2002' OR a.name LIKE '%Rutin%' OR a.name LIKE '%Operasional%' OR a.name LIKE '%Listrik%' OR a.name LIKE '%Sewa%') AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    
    $gaji = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND (a.code='2003' OR a.name LIKE '%Gaji%' OR a.name LIKE '%Tunjangan%') AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;

    $modal = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND (a.code='2004' OR a.name LIKE '%Iklan%' OR a.name LIKE '%Internet%' OR a.name LIKE '%Marketing%') AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;

    $material = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND (a.code='2001' OR a.code='2005' OR a.name LIKE '%HPP%' OR a.name LIKE '%Material%' OR a.name LIKE '%Stok%' OR a.name LIKE '%Pembelian%') AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;

    $all_expense = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    $defined_expense = $rutin + $gaji + $modal + $material;
    $taktis = $all_expense - $defined_expense;
    if($taktis < 0) $taktis = 0; 

    $total_keluar = $rutin + $gaji + $modal + $material + $taktis;
    $profit = $omzet - $total_keluar;

    $report_data[] = [
        'label' => $month_label,
        'omzet' => $omzet,
        'rutin' => $rutin,
        'gaji' => $gaji,
        'modal' => $modal,
        'material' => $material,
        'taktis' => $taktis,
        'total_keluar' => $total_keluar,
        'profit' => $profit
    ];

    $totals['omzet'] += $omzet;
    $totals['rutin'] += $rutin;
    $totals['gaji'] += $gaji;
    $totals['modal'] += $modal;
    $totals['material'] += $material;
    $totals['taktis'] += $taktis;
    $totals['total_keluar'] += $total_keluar;
    $totals['profit'] += $profit;

    // Increment Month
    $current_ts = strtotime("+1 month", $current_ts);
}

$total_margin = ($totals['omzet'] > 0) ? ($totals['profit'] / $totals['omzet']) * 100 : 0;
?>

<!-- Load Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    @media print {
        @page { size: landscape; margin: 5mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: sans-serif; font-size: 9px; }
        .no-print { display: none !important; }
        table { width: 100%; border-collapse: collapse; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #000 !important; padding: 4px; }
        
        .header-print { display: block !important; margin-bottom: 20px; }
        
        /* Kop Surat Styles - Consistent v0.0.1 */
        .kop-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 4px double #000;
            width: 100%;
        }
        .kop-logo, .kop-spacer { width: 120px; display: flex; justify-content: center; align-items: center; }
        .kop-logo img { max-height: 90px; max-width: 100%; }
        .kop-text { flex-grow: 1; text-align: center; }
        .kop-company { 
            font-size: 24pt; 
            font-weight: 900; 
            text-transform: uppercase; 
            margin: 0; 
            line-height: 1.1; 
            color: #000;
            letter-spacing: 1px;
        }
        .kop-address { font-size: 11pt; margin: 5px 0 0 0; line-height: 1.3; }
        .kop-npwp { font-size: 11pt; font-weight: bold; margin-top: 3px; }

        .bg-orange-500 { background-color: #f97316 !important; color: white !important; }
        .bg-purple-600 { background-color: #9333ea !important; color: white !important; }
        .bg-pink-500 { background-color: #ec4899 !important; color: white !important; }
        .bg-red-600 { background-color: #dc2626 !important; color: white !important; }
        .bg-green-500 { background-color: #22c55e !important; color: white !important; }
        .bg-gray-100 { background-color: #f3f4f6 !important; }
    }
    .header-print { display: none; }
    
    /* Utility Class untuk sel gabungan */
    .cell-nominal { font-weight: bold; font-size: 1.1em; }
    .cell-percent { font-size: 0.85em; font-weight: normal; margin-top: 2px; }
</style>

<!-- FILTER SECTION (Updated to Match Laporan Pajak) -->
<div class="bg-white p-6 rounded shadow mb-6 no-print border-l-4 border-purple-600">
    <h3 class="font-bold text-gray-700 mb-4"><i class="fas fa-filter"></i> Filter Laporan Internal</h3>
    <form class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="page" value="laporan_internal">
        
        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start_date ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500">
        </div>

        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end_date ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500">
        </div>
        
        <!-- Spacer / Empty Col if needed -->
        <div class="hidden md:block"></div>

        <div class="flex gap-2 md:col-span-1">
            <button class="bg-purple-600 text-white px-4 py-2 rounded font-bold hover:bg-purple-700 flex-1 text-sm shadow">
                <i class="fas fa-search"></i> Tampilkan
            </button>
            <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 flex-1 text-sm shadow">
                <i class="fas fa-print"></i> Cetak
            </button>
            <button type="button" onclick="savePDF()" class="bg-red-600 text-white px-4 py-2 rounded font-bold hover:bg-red-700 flex-1 text-sm shadow">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </form>
</div>

<!-- KONTEN LAPORAN -->
<div id="report_content" class="bg-white p-6 rounded shadow">
    
    <!-- Header Print (Updated with Kop Surat Standard) -->
    <div class="header-print">
        <div class="kop-container">
            <div class="kop-logo">
                <?php if(!empty($config['company_logo'])): ?>
                    <img src="<?= $config['company_logo'] ?>">
                <?php endif; ?>
            </div>
            <div class="kop-text">
                <h1 class="kop-company"><?= $config['company_name'] ?></h1>
                <div class="kop-address"><?= nl2br($config['company_address'] ?? '') ?></div>
                <?php if(!empty($config['company_npwp'])): ?>
                    <div class="kop-npwp">NPWP: <?= $config['company_npwp'] ?></div>
                <?php endif; ?>
            </div>
            <div class="kop-spacer"></div>
        </div>
        <div style="text-align: center; margin-bottom: 20px;">
            <h3 style="font-size: 14pt; font-weight: bold; text-transform: uppercase; text-decoration: underline; margin-bottom: 5px;">Laporan Keuangan Internal</h3>
            <p style="font-size: 10pt; font-weight: bold;">Periode: <?= date('d M Y', strtotime($start_date)) ?> s/d <?= date('d M Y', strtotime($end_date)) ?></p>
        </div>
    </div>

    <!-- Grafik Visual -->
    <div class="mb-8 h-64 w-full no-print">
        <canvas id="profitChart"></canvas>
    </div>

    <!-- TABEL GABUNGAN (NOMINAL & PERSENTASE) -->
    <div class="mb-8">
        <h3 class="font-bold text-lg mb-2 text-gray-800 no-print">Detail Laporan Bulanan</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse border border-gray-300">
                <thead>
                    <tr class="text-white text-xs uppercase tracking-wider h-12">
                        <th class="bg-orange-500 p-2 text-center w-20 align-middle">Periode</th>
                        <th class="bg-purple-600 p-2 text-center align-middle">Total Pemasukan<br>(Omzet)</th>
                        <th class="bg-pink-500 p-2 text-center align-middle">Pengeluaran<br>Rutin</th>
                        <th class="bg-pink-500 p-2 text-center align-middle">Gaji<br>All Tim</th>
                        <th class="bg-pink-500 p-2 text-center align-middle">Modal<br>Usaha</th>
                        <th class="bg-pink-500 p-2 text-center align-middle">Pembelian<br>Material</th>
                        <th class="bg-pink-500 p-2 text-center align-middle">Dana<br>Taktis</th>
                        <th class="bg-red-600 p-2 text-center align-middle">Total<br>Pengeluaran</th>
                        <th class="bg-green-500 p-2 text-center align-middle">PROFIT<br>(Margin)</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700">
                    <?php 
                    $count_rows = 0;
                    foreach($report_data as $d): 
                        $count_rows++;
                        $om = $d['omzet'];
                        
                        // Helper Calc Percent
                        $pct = function($val, $base) {
                            if($base <= 0) return '0%';
                            return number_format(($val/$base)*100, 2) . '%';
                        };
                    ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-200 align-top">
                        <td class="p-2 text-center font-bold bg-gray-50 align-middle whitespace-nowrap"><?= $d['label'] ?></td>
                        
                        <!-- OMZET -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal text-purple-900"><?= formatRupiah($d['omzet']) ?></div>
                            <div class="cell-percent text-purple-600">100%</div>
                        </td>

                        <!-- RUTIN -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal"><?= formatRupiah($d['rutin']) ?></div>
                            <div class="cell-percent text-gray-500"><?= $pct($d['rutin'], $om) ?></div>
                        </td>

                        <!-- GAJI -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal"><?= formatRupiah($d['gaji']) ?></div>
                            <div class="cell-percent text-gray-500"><?= $pct($d['gaji'], $om) ?></div>
                        </td>

                        <!-- MODAL -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal"><?= formatRupiah($d['modal']) ?></div>
                            <div class="cell-percent text-gray-500"><?= $pct($d['modal'], $om) ?></div>
                        </td>

                        <!-- MATERIAL -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal"><?= formatRupiah($d['material']) ?></div>
                            <div class="cell-percent text-gray-500"><?= $pct($d['material'], $om) ?></div>
                        </td>

                        <!-- TAKTIS -->
                        <td class="p-2 border-r">
                            <div class="cell-nominal"><?= formatRupiah($d['taktis']) ?></div>
                            <div class="cell-percent text-gray-500"><?= $pct($d['taktis'], $om) ?></div>
                        </td>

                        <!-- TOTAL KELUAR -->
                        <td class="p-2 border-r bg-red-50">
                            <div class="cell-nominal text-red-700"><?= formatRupiah($d['total_keluar']) ?></div>
                            <div class="cell-percent text-red-500"><?= $pct($d['total_keluar'], $om) ?></div>
                        </td>

                        <!-- PROFIT -->
                        <td class="p-2 bg-green-50">
                            <div class="cell-nominal <?= $d['profit']>=0?'text-green-700':'text-red-600' ?>">
                                <?= formatRupiah($d['profit']) ?>
                            </div>
                            <div class="cell-percent <?= $d['profit']>=0?'text-green-600':'text-red-500' ?>">
                                <?= $pct($d['profit'], $om) ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if($count_rows === 0): ?>
                        <tr><td colspan="9" class="p-6 text-center text-gray-500 italic">Tidak ada data untuk periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="font-bold bg-gray-100 border-t-2 border-gray-400 text-sm align-top">
                        <td class="p-2 text-center align-middle">TOTAL</td>
                        
                        <?php 
                        // Helper for Total Percent
                        $pctTot = function($val, $base) {
                            if($base <= 0) return '0%';
                            return number_format(($val/$base)*100, 2) . '%';
                        };
                        ?>

                        <td class="p-2 border-r">
                            <div class="text-lg"><?= formatRupiah($totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r">
                            <div><?= formatRupiah($totals['rutin']) ?></div>
                            <div class="text-xs font-normal text-gray-600"><?= $pctTot($totals['rutin'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r">
                            <div><?= formatRupiah($totals['gaji']) ?></div>
                            <div class="text-xs font-normal text-gray-600"><?= $pctTot($totals['gaji'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r">
                            <div><?= formatRupiah($totals['modal']) ?></div>
                            <div class="text-xs font-normal text-gray-600"><?= $pctTot($totals['modal'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r">
                            <div><?= formatRupiah($totals['material']) ?></div>
                            <div class="text-xs font-normal text-gray-600"><?= $pctTot($totals['material'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r">
                            <div><?= formatRupiah($totals['taktis']) ?></div>
                            <div class="text-xs font-normal text-gray-600"><?= $pctTot($totals['taktis'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 border-r bg-red-100 text-red-900">
                            <div><?= formatRupiah($totals['total_keluar']) ?></div>
                            <div class="text-xs font-normal text-red-700"><?= $pctTot($totals['total_keluar'], $totals['omzet']) ?></div>
                        </td>

                        <td class="p-2 bg-green-100 text-green-900">
                            <div class="text-lg"><?= formatRupiah($totals['profit']) ?></div>
                            <div class="text-xs font-bold text-green-700"><?= $pctTot($totals['profit'], $totals['omzet']) ?></div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
// CHART VISUALIZATION
const ctx = document.getElementById('profitChart').getContext('2d');

const labels = <?= json_encode(array_column($report_data, 'label')) ?>;
const omzetData = <?= json_encode(array_column($report_data, 'omzet')) ?>;
const expenseData = <?= json_encode(array_column($report_data, 'total_keluar')) ?>;
const profitData = <?= json_encode(array_column($report_data, 'profit')) ?>;

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Profit (Laba)',
                data: profitData,
                type: 'line',
                borderColor: '#22c55e',
                backgroundColor: '#22c55e',
                borderWidth: 3,
                tension: 0.3,
                yAxisID: 'y',
                order: 0
            },
            {
                label: 'Omzet',
                data: omzetData,
                backgroundColor: 'rgba(147, 51, 234, 0.7)', 
                borderColor: '#9333ea',
                borderWidth: 1,
                order: 1
            },
            {
                label: 'Total Pengeluaran',
                data: expenseData,
                backgroundColor: 'rgba(220, 38, 38, 0.7)',
                borderColor: '#dc2626',
                borderWidth: 1,
                order: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f3f4f6' },
                ticks: {
                    callback: function(value) {
                        return 'Rp ' + value.toLocaleString('id-ID', {notation: 'compact'});
                    }
                }
            }
        },
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        }
    }
});

function savePDF() {
    const element = document.getElementById('report_content');
    const header = document.querySelector('.header-print');
    
    // Header already handled by media print CSS for window.print()
    // For html2pdf, we need to make sure the style matches
    
    const opt = {
        margin:       5,
        filename:     'Laporan_Internal_<?= date('Ymd') ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    
    // Button Loading State
    const btn = event.currentTarget;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Proses...';
    btn.disabled = true;

    // Show header explicitly if needed for HTML2PDF rendering context
    if(header) header.style.display = 'block';

    html2pdf().set(opt).from(element).save().then(() => {
        btn.innerHTML = oldText;
        btn.disabled = false;
        if(header) header.style.display = ''; // Revert to CSS control
    });
}
</script>
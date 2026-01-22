<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'ALL';

// --- CONFIG & HEADER DATA ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

// Judul Laporan Dinamis
$report_titles = [
    'ALL' => 'LAPORAN KEUANGAN LENGKAP',
    'LABA_RUGI' => 'LAPORAN LABA RUGI (INCOME STATEMENT)',
    'NERACA' => 'LAPORAN POSISI KEUANGAN (NERACA)',
    'ARUS_KAS' => 'LAPORAN ARUS KAS (CASH FLOW)',
    'PERUBAHAN_MODAL' => 'LAPORAN PERUBAHAN MODAL'
];
$current_title = $report_titles[$report_type] ?? 'LAPORAN KEUANGAN';

// --- LOGIC: STANDARD REPORTS (LABA RUGI, NERACA, DLL) ---
$revenue = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='INCOME' AND a.code LIKE '1%' AND f.date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
$cogs = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code='2001' AND f.date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
$opex = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code LIKE '2%' AND a.code != '2001' AND f.date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
$asset_purchase = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code='3003' AND f.date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;

$gross_profit = $revenue - $cogs;
$net_profit_period = $gross_profit - $opex;

// Data Historis
$initial_capital = $config['initial_capital'] ?? 0;
$rev_before = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date < '$start'")->fetchColumn() ?: 0;
$exp_before = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date < '$start'")->fetchColumn() ?: 0;
$retained_earnings_before = $rev_before - $exp_before;
$retained_earnings_total = $retained_earnings_before + $net_profit_period;

$total_rev_all = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date <= '$end'")->fetchColumn() ?: 0;
$total_exp_all = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date <= '$end'")->fetchColumn() ?: 0;
$cash_balance = $initial_capital + $total_rev_all - $total_exp_all;
$inventory_value = $pdo->query("SELECT SUM(stock * buy_price) FROM products")->fetchColumn() ?: 0;
$liabilities = 0; 
?>

<!-- Load Library PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- STYLE KHUSUS PRINT & PDF -->
<style>
    @media print {
        @page { size: A4 portrait; margin: 10mm; } 
        body { background: white; font-family: 'Times New Roman', Times, serif; color: black; font-size: 12pt; }
        .no-print { display: none !important; }
        .print-break { page-break-inside: avoid; }
        .shadow, .rounded, .border { box-shadow: none !important; border-radius: 0 !important; border: none !important; }
        .report-section { border: 1px solid #000 !important; margin-bottom: 20px; padding: 10px; }
        
        /* KOP SURAT STYLE - PERFECT CENTER BALANCED */
        .kop-container {
            display: flex;
            align-items: center;
            justify-content: space-between; /* Kunci agar logo kiri dan spacer kanan berada di ujung */
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 4px double #000;
            width: 100%;
        }
        .kop-logo, .kop-spacer {
            width: 120px; /* Lebar tetap agar seimbang */
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .kop-logo img {
            max-height: 90px;
            max-width: 100%;
        }
        .kop-text {
            flex-grow: 1; /* Mengisi ruang tengah */
            text-align: center;
        }
        
        .kop-company { 
            font-size: 24pt; /* Huruf Lebih Besar */
            font-weight: 900; 
            text-transform: uppercase; 
            margin: 0; 
            line-height: 1.1; 
            color: #000;
            letter-spacing: 1px;
        }
        .kop-address { 
            font-size: 11pt; /* Huruf Kecil */
            margin: 5px 0 0 0; 
            line-height: 1.3; 
        }
        .kop-npwp { 
            font-size: 11pt; 
            font-weight: bold; 
            margin-top: 3px; 
        }

        table.data-table { border-collapse: collapse; width: 100%; }
        table.data-table th, table.data-table td { border-bottom: 1px dotted #ccc; padding: 4px; font-size: 11pt; }
        .font-bold { font-weight: bold; }
        .text-right { text-align: right; }
        .border-t-black { border-top: 1px solid #000; }
        
        /* Helpers */
        .bg-gray-100, .bg-blue-100, .bg-green-100, .bg-yellow-50 { background-color: transparent !important; }
    }
</style>

<!-- FILTER SECTION -->
<div class="bg-white p-6 rounded shadow mb-6 no-print border-l-4 border-blue-600">
    <h3 class="font-bold text-gray-700 mb-4"><i class="fas fa-filter"></i> Filter Laporan Pajak</h3>
    <form class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="page" value="laporan_keuangan">
        
        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Jenis Laporan</label>
            <select name="report_type" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 bg-gray-50">
                <option value="ALL" <?= $report_type=='ALL'?'selected':'' ?>>Laporan Lengkap</option>
                <option value="LABA_RUGI" <?= $report_type=='LABA_RUGI'?'selected':'' ?>>Laba Rugi (Income Statement)</option>
                <option value="PERUBAHAN_MODAL" <?= $report_type=='PERUBAHAN_MODAL'?'selected':'' ?>>Perubahan Modal (Equity)</option>
                <option value="NERACA" <?= $report_type=='NERACA'?'selected':'' ?>>Neraca (Balance Sheet)</option>
                <option value="ARUS_KAS" <?= $report_type=='ARUS_KAS'?'selected':'' ?>>Arus Kas (Cash Flow)</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="flex gap-2">
            <button class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 flex-1 text-sm">
                <i class="fas fa-search"></i> Tampilkan
            </button>
            <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 flex-1 text-sm">
                <i class="fas fa-print"></i> Cetak
            </button>
            <button type="button" onclick="savePDF()" class="bg-red-600 text-white px-4 py-2 rounded font-bold hover:bg-red-700 flex-1 text-sm">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </form>
</div>

<!-- REPORT CONTAINER -->
<div id="report_content" class="bg-white p-8 rounded shadow max-w-4xl mx-auto print:p-0 print:shadow-none print:max-w-none">
    
    <!-- KOP SURAT STANDAR (PORTRAIT) -->
    <div id="report_header" class="hidden print:block">
        <div class="kop-container">
            <!-- Logo Kiri -->
            <div class="kop-logo">
                <?php if(!empty($config['company_logo'])): ?>
                    <img src="<?= $config['company_logo'] ?>">
                <?php endif; ?>
            </div>
            
            <!-- Teks Tengah -->
            <div class="kop-text">
                <h1 class="kop-company"><?= $config['company_name'] ?? 'NAMA PERUSAHAAN' ?></h1>
                <div class="kop-address"><?= nl2br(htmlspecialchars($config['company_address'] ?? '')) ?></div>
                <?php if(!empty($config['company_npwp'])): ?>
                    <div class="kop-npwp">NPWP: <?= $config['company_npwp'] ?></div>
                <?php endif; ?>
            </div>

            <!-- Spacer Kanan (Agar center seimbang dengan logo) -->
            <div class="kop-spacer"></div>
        </div>
    </div>

    <!-- JUDUL LAPORAN -->
    <div class="text-center mb-8">
        <h2 class="text-xl font-bold text-blue-900 uppercase print:text-black border-b-2 border-gray-800 inline-block px-4 pb-1 mb-2 print:border-none print:text-lg print:underline">
            <?= $current_title ?>
        </h2>
        <p class="text-sm font-bold text-gray-500 print:text-black">
            Periode: <?= date('d F Y', strtotime($start)) ?> - <?= date('d F Y', strtotime($end)) ?>
        </p>
    </div>

    <div class="space-y-8">

        <!-- STANDARD REPORTS (LABA RUGI, NERACA, DLL) -->
        <?php if($report_type == 'ALL' || $report_type == 'LABA_RUGI'): ?>
        <div class="report-section print-break">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 print:text-black print:border-black print:text-base">1. LAPORAN LABA RUGI (INCOME STATEMENT)</h3>
            <div class="space-y-1 text-sm print:text-base">
                <div class="flex justify-between font-bold text-green-700 print:text-black mb-2">
                    <span>PENDAPATAN USAHA</span>
                    <span><?= formatRupiah($revenue) ?></span>
                </div>
                
                <?php 
                $rev_det = $pdo->query("SELECT a.name, SUM(f.amount) as total FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='INCOME' AND a.code LIKE '1%' AND f.date BETWEEN '$start' AND '$end' GROUP BY a.name");
                while($r = $rev_det->fetch()): ?>
                    <div class="flex justify-between pl-4 text-gray-500 italic print:text-black">
                        <span>- <?= $r['name'] ?></span>
                        <span><?= formatRupiah($r['total']) ?></span>
                    </div>
                <?php endwhile; ?>

                <div class="flex justify-between text-red-600 pl-4 print:text-black mt-2">
                    <span>(-) Harga Pokok Penjualan (HPP)</span>
                    <span>(<?= formatRupiah($cogs) ?>)</span>
                </div>
                
                <div class="flex justify-between font-bold border-t border-gray-300 pt-2 mt-2 print:border-black print:border-t-2">
                    <span>LABA KOTOR</span>
                    <span><?= formatRupiah($gross_profit) ?></span>
                </div>

                <div class="mt-4 font-bold text-gray-700 print:text-black">BEBAN OPERASIONAL</div>
                <?php 
                $opex_det = $pdo->query("SELECT a.name, SUM(f.amount) as total FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code LIKE '2%' AND a.code != '2001' AND f.date BETWEEN '$start' AND '$end' GROUP BY a.name");
                while($o = $opex_det->fetch()): ?>
                    <div class="flex justify-between pl-4 text-gray-600 print:text-black">
                        <span><?= $o['name'] ?></span>
                        <span>(<?= formatRupiah($o['total']) ?>)</span>
                    </div>
                <?php endwhile; ?>

                <div class="flex justify-between font-bold text-lg border-t-2 border-gray-800 pt-2 mt-4 bg-gray-50 p-2 print:bg-transparent print:border-black print:border-double print:border-t-4">
                    <span>LABA BERSIH (NET PROFIT)</span>
                    <span class="<?= $net_profit_period >= 0 ? 'text-blue-600' : 'text-red-600' ?> print:text-black"><?= formatRupiah($net_profit_period) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- LAPORAN PERUBAHAN MODAL -->
        <?php if($report_type == 'ALL' || $report_type == 'PERUBAHAN_MODAL'): ?>
        <div class="report-section print-break">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 print:text-black print:border-black print:text-base">2. LAPORAN PERUBAHAN MODAL</h3>
            <div class="space-y-2 text-sm print:text-base">
                <div class="flex justify-between font-bold">
                    <span>Modal Awal Perusahaan</span>
                    <span><?= formatRupiah($initial_capital) ?></span>
                </div>
                <div class="flex justify-between">
                    <span>Laba Ditahan (Awal Periode)</span>
                    <span><?= formatRupiah($retained_earnings_before) ?></span>
                </div>
                <div class="flex justify-between font-bold text-blue-600 print:text-black border-b border-gray-300 pb-1 print:border-black">
                    <span>(+) Laba Bersih Periode Berjalan</span>
                    <span><?= formatRupiah($net_profit_period) ?></span>
                </div>
                <div class="flex justify-between font-bold text-lg pt-2 bg-gray-50 p-2 print:bg-transparent">
                    <span>MODAL AKHIR (EKUITAS)</span>
                    <span><?= formatRupiah($initial_capital + $retained_earnings_total) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- NERACA -->
        <?php if($report_type == 'ALL' || $report_type == 'NERACA'): ?>
        <div class="report-section print-break">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 print:text-black print:border-black print:text-base">3. NERACA (BALANCE SHEET)</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 print:block">
                <!-- AKTIVA -->
                <div class="print:mb-4">
                    <h4 class="font-bold text-center bg-gray-100 p-1 mb-2 print:bg-transparent print:border-b print:border-black print:text-left">AKTIVA (ASET)</h4>
                    <div class="space-y-2 text-sm print:text-base">
                        <div class="font-bold text-gray-700 print:text-black">Aset Lancar</div>
                        <div class="flex justify-between pl-4">
                            <span>Kas & Setara Kas</span>
                            <span class="font-mono"><?= formatRupiah($cash_balance) ?></span>
                        </div>
                        <div class="flex justify-between pl-4">
                            <span>Persediaan Barang</span>
                            <span class="font-mono"><?= formatRupiah($inventory_value) ?></span>
                        </div>
                        <div class="flex justify-between font-bold border-t-2 border-black pt-2 mt-4 bg-gray-50 p-1 print:bg-transparent">
                            <span>TOTAL AKTIVA</span>
                            <span><?= formatRupiah($cash_balance + $inventory_value) ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- PASIVA -->
                <div>
                    <h4 class="font-bold text-center bg-gray-100 p-1 mb-2 print:bg-transparent print:border-b print:border-black print:text-left">PASIVA (KEWAJIBAN & EKUITAS)</h4>
                    <div class="space-y-2 text-sm print:text-base">
                        <div class="font-bold text-gray-700 print:text-black">Ekuitas</div>
                        <div class="flex justify-between pl-4">
                            <span>Modal Disetor</span>
                            <span class="font-mono"><?= formatRupiah($initial_capital) ?></span>
                        </div>
                        <div class="flex justify-between pl-4">
                            <span>Laba Ditahan</span>
                            <span class="font-mono"><?= formatRupiah($retained_earnings_total) ?></span>
                        </div>
                        <div class="flex justify-between font-bold border-t-2 border-black pt-2 mt-4 bg-gray-50 p-1 print:bg-transparent">
                            <span>TOTAL PASIVA</span>
                            <span><?= formatRupiah($liabilities + $initial_capital + $retained_earnings_total) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ARUS KAS -->
        <?php if($report_type == 'ALL' || $report_type == 'ARUS_KAS'): ?>
        <div class="report-section print-break">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 print:text-black print:border-black print:text-base">4. LAPORAN ARUS KAS</h3>
            <div class="space-y-2 text-sm print:text-base">
                <div class="font-bold text-gray-700 print:text-black">ARUS KAS OPERASIONAL</div>
                <div class="flex justify-between pl-4">
                    <span>Penerimaan (Pendapatan)</span>
                    <span><?= formatRupiah($revenue) ?></span>
                </div>
                <div class="flex justify-between pl-4 text-red-600 print:text-black">
                    <span>Pembayaran Operasional</span>
                    <span>(<?= formatRupiah($opex) ?>)</span>
                </div>
                <div class="flex justify-between pl-4 text-red-600 print:text-black">
                    <span>Pembelian Stok/Aset</span>
                    <span>(<?= formatRupiah($asset_purchase) ?>)</span>
                </div>
                <div class="flex justify-between font-bold border-t border-gray-300 pt-2 mt-2 print:border-black">
                    <span>KENAIKAN KAS BERSIH</span>
                    <span><?= formatRupiah($revenue - $opex - $asset_purchase) ?></span>
                </div>
                <div class="flex justify-between font-bold text-lg bg-gray-50 p-2 mt-2 print:bg-transparent print:border-t-2 print:border-double print:border-black">
                    <span>SALDO KAS AKHIR</span>
                    <span><?= formatRupiah($cash_balance) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TANDA TANGAN (Hanya Print & PDF) -->
        <div id="report_footer" class="hidden print:flex justify-between mt-12 pt-8">
            <div class="text-center w-1/3">
                <p class="mb-20">Disiapkan Oleh,</p>
                <p class="font-bold border-t border-black inline-block px-8">Bag. Keuangan</p>
            </div>
            <div class="text-center w-1/3">
                <p class="mb-20">Diperiksa Oleh,</p>
                <p class="font-bold border-t border-black inline-block px-8">Manager</p>
            </div>
        </div>

    </div>
</div>

<script>
function savePDF() {
    const element = document.getElementById('report_content');
    const header = document.getElementById('report_header');
    const footer = document.getElementById('report_footer');
    
    // Tampilkan Header dan Footer sementara agar masuk ke PDF
    header.classList.remove('hidden');
    footer.classList.remove('hidden');
    footer.classList.add('flex');

    const opt = {
        margin:       10,
        filename:     'Laporan_Keuangan_Pajak_<?= date('Ymd') ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    const btn = event.currentTarget;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Proses...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        // Kembalikan ke state awal (hide header/footer)
        header.classList.add('hidden');
        footer.classList.add('hidden');
        footer.classList.remove('flex');
        
        btn.innerHTML = oldText;
        btn.disabled = false;
    });
}
</script>
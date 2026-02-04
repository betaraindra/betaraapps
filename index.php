                <!-- COLLAPSIBLE: DATA KEUANGAN & INVENTORI -->
                <button onclick="toggleMenu('menu-data', this)" class="w-full flex justify-between items-center mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider hover:text-white focus:outline-none transition-colors">
                    <span>Data Keuangan & Inventori</span>
                    <i class="fas <?= $is_menu_data_open ? 'fa-minus' : 'fa-plus' ?> text-[10px]"></i>
                </button>
                <div id="menu-data" class="<?= $is_menu_data_open ? '' : 'hidden' ?> space-y-1 mt-1 pl-2 border-l-2 border-slate-700 ml-2">
                    <?php if ($access_inventory): ?>
                        <a href="?page=data_barang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_barang' ? 'text-white' : 'text-slate-300' ?>">
                            <i class="fas fa-box w-5 text-center mr-1"></i> Data Barang
                        </a>
                    <?php endif; ?>
                    <?php if ($access_finance): ?>
                        <a href="?page=data_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_keuangan' ? 'text-white' : 'text-slate-300' ?>">
                            <i class="fas fa-file-invoice-dollar w-5 text-center mr-1"></i> Data Keuangan
                        </a>
                    <?php endif; ?>
                    <a href="?page=data_transaksi" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_transaksi' ? 'text-white' : 'text-slate-300' ?>">
                        <i class="fas fa-list-alt w-5 text-center mr-1"></i> Semua Transaksi
                    </a>
                    <?php if ($access_inventory): ?>
                        <a href="?page=data_wilayah" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= ($page == 'data_wilayah' || $page == 'detail_gudang') ? 'text-white' : 'text-slate-300' ?>">
                            <i class="fas fa-map-marked-alt w-5 text-center mr-1"></i> Data Wilayah (Gudang)
                        </a>
                    <?php endif; ?>
                </div>
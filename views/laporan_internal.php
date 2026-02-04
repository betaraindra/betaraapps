                        <div class="flex gap-2">
                            <div class="w-1/2">
                                <label class="block text-xs font-bold text-gray-600 mb-1">Akun</label>
                                <select name="account_id" class="border p-2 rounded text-sm w-full bg-white">
                                    <option value="ALL">Semua</option>
                                    <?php foreach($all_accounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" <?= ($_GET['account_id']??'') == $acc['id'] ? 'selected' : '' ?>><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="w-1/2">
                                <label class="block text-xs font-bold text-gray-600 mb-1">Tipe</label>
                                <select name="type" class="border p-2 rounded text-sm w-full bg-white">
                                    <option value="INCOME" <?= ($_GET['type']??'')=='INCOME'?'selected':'' ?>>Pemasukan</option>
                                    <option value="EXPENSE" <?= ($_GET['type']??'')=='EXPENSE'?'selected':'' ?>>Pengeluaran</option>
                                </select>
                            </div>
                        </div>
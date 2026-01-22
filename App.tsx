import React, { useState, useEffect, useMemo } from 'react';
import { 
  LayoutDashboard, Package, TrendingUp, TrendingDown, Settings, 
  Users, FileText, Menu, LogOut, Upload, Download, Trash2, 
  Plus, Search, Save, AlertTriangle, Scan, History, DollarSign,
  Archive, Database
} from 'lucide-react';
import { 
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, 
  LineChart, Line, PieChart, Pie, Cell 
} from 'recharts';
import * as Types from './types';
import * as DB from './services/storageService';
import BarcodeScanner from './components/BarcodeScanner';

// --- Components ---

// Button Component
const Button: React.FC<React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' | 'success' }> = ({ children, variant = 'primary', className = '', ...props }) => {
  const baseStyle = "px-4 py-2 rounded-lg font-medium transition flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed";
  const variants = {
    primary: "bg-blue-600 text-white hover:bg-blue-700",
    secondary: "bg-white text-gray-700 border border-gray-300 hover:bg-gray-50",
    danger: "bg-red-600 text-white hover:bg-red-700",
    success: "bg-green-600 text-white hover:bg-green-700",
  };
  return <button className={`${baseStyle} ${variants[variant]} ${className}`} {...props}>{children}</button>;
};

// Input Component
const Input: React.FC<React.InputHTMLAttributes<HTMLInputElement> & { label?: string }> = ({ label, className = '', ...props }) => (
  <div className="flex flex-col gap-1 w-full">
    {label && <label className="text-sm font-medium text-gray-700">{label}</label>}
    <input className={`px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none ${className}`} {...props} />
  </div>
);

// Select Component
const Select: React.FC<React.SelectHTMLAttributes<HTMLSelectElement> & { label?: string }> = ({ label, children, className = '', ...props }) => (
  <div className="flex flex-col gap-1 w-full">
    {label && <label className="text-sm font-medium text-gray-700">{label}</label>}
    <select className={`px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white ${className}`} {...props}>
      {children}
    </select>
  </div>
);

// Card Component
const Card: React.FC<{ children: React.ReactNode; title?: string; className?: string }> = ({ children, title, className = '' }) => (
  <div className={`bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden ${className}`}>
    {title && <div className="px-6 py-4 border-b border-gray-100 font-semibold text-gray-800">{title}</div>}
    <div className="p-6">{children}</div>
  </div>
);

// Modal Component
const Modal: React.FC<{ isOpen: boolean; onClose: () => void; title: string; children: React.ReactNode }> = ({ isOpen, onClose, title, children }) => {
  if (!isOpen) return null;
  return (
    <div className="fixed inset-0 z-50 bg-black bg-opacity-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div className="flex justify-between items-center p-4 border-b">
          <h3 className="text-lg font-semibold">{title}</h3>
          <button onClick={onClose}><Trash2 className="rotate-45" size={20} /></button>
        </div>
        <div className="p-4">{children}</div>
      </div>
    </div>
  );
};

// Login Screen
const LoginScreen: React.FC<{ onLogin: (user: Types.User) => void }> = ({ onLogin }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    const data = DB.getAppData();
    const user = data.users.find(u => u.username === username && u.password === password);
    if (user) {
      onLogin(user);
    } else {
      setError('Username atau password salah');
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-500 to-indigo-700 flex items-center justify-center p-4">
      <Card className="w-full max-w-md bg-white/95 backdrop-blur shadow-2xl">
        <div className="text-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">SIKI Login</h1>
          <p className="text-gray-500">Sistem Informasi Keuangan & Inventori</p>
        </div>
        <form onSubmit={handleLogin} className="space-y-4">
          <Input label="Username" value={username} onChange={e => setUsername(e.target.value)} placeholder="Contoh: superadmin" />
          <Input label="Password" type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Contoh: admin" />
          {error && <p className="text-red-500 text-sm">{error}</p>}
          <Button type="submit" className="w-full">Masuk</Button>
          <div className="mt-4 text-xs text-gray-500 text-center">
            <p>Demo Accounts (Pass: admin):</p>
            <p>superadmin, gudang, keuangan</p>
          </div>
        </form>
      </Card>
    </div>
  );
};

// --- Main App Component ---

enum View {
  DASHBOARD,
  INPUT_TRANSAKSI,
  INVENTORY_ITEMS,
  INVENTORY_WAREHOUSE,
  INVENTORY_REPORT,
  FINANCE_DASHBOARD,
  FINANCE_ACCOUNTS,
  FINANCE_TRANSACTIONS,
  FINANCE_REPORT,
  USERS,
  SETTINGS,
  LOGS
}

export default function App() {
  const [user, setUser] = useState<Types.User | null>(null);
  const [view, setView] = useState<View>(View.DASHBOARD);
  const [isSidebarOpen, setSidebarOpen] = useState(true);
  const [data, setData] = useState<Types.AppData>(DB.getAppData());
  const [loading, setLoading] = useState(false);

  // Refresh data helper
  const refreshData = () => setData(DB.getAppData());

  // Check auth
  useEffect(() => {
    const storedUser = sessionStorage.getItem('SIKI_USER');
    if (storedUser) setUser(JSON.parse(storedUser));
  }, []);

  const handleLogin = (u: Types.User) => {
    setUser(u);
    sessionStorage.setItem('SIKI_USER', JSON.stringify(u));
    DB.addLog('LOGIN', u.id, `User ${u.username} logged in`);
    refreshData();
  };

  const handleLogout = () => {
    if (user) DB.addLog('LOGOUT', user.id, `User ${user.username} logged out`);
    setUser(null);
    sessionStorage.removeItem('SIKI_USER');
  };

  if (!user) return <LoginScreen onLogin={handleLogin} />;

  // --- Layout Wrappers ---

  const NavItem = ({ icon: Icon, label, active, onClick }: any) => (
    <button 
      onClick={onClick}
      className={`w-full flex items-center gap-3 px-4 py-3 text-sm font-medium transition-colors ${active ? 'bg-blue-50 text-blue-600 border-r-4 border-blue-600' : 'text-gray-600 hover:bg-gray-50'}`}
    >
      <Icon size={18} />
      <span>{label}</span>
    </button>
  );

  const SectionHeader = ({ title, subtitle }: any) => (
    <div className="mb-6">
      <h2 className="text-2xl font-bold text-gray-800">{title}</h2>
      {subtitle && <p className="text-gray-500 mt-1">{subtitle}</p>}
    </div>
  );

  // --- Views ---

  const DashboardHome = () => {
    // Basic stats
    const totalRevenue = data.financeTransactions.filter(t => t.type === Types.TransactionType.INCOME).reduce((acc, t) => acc + t.amount, 0);
    const totalExpense = data.financeTransactions.filter(t => t.type === Types.TransactionType.EXPENSE).reduce((acc, t) => acc + t.amount, 0);
    const lowStockItems = data.products.filter(p => p.stock < 10);

    return (
      <div className="space-y-6">
        <SectionHeader title="Dashboard Utama" subtitle={`Selamat datang, ${user.username}`} />
        
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card className="border-l-4 border-l-blue-500">
            <div className="text-gray-500 text-sm">Saldo Saat Ini</div>
            <div className="text-2xl font-bold mt-1 text-blue-600">Rp {(data.settings.initialBalance + totalRevenue - totalExpense).toLocaleString('id-ID')}</div>
          </Card>
          <Card className="border-l-4 border-l-green-500">
            <div className="text-gray-500 text-sm">Pemasukan (Total)</div>
            <div className="text-2xl font-bold mt-1 text-green-600">Rp {totalRevenue.toLocaleString('id-ID')}</div>
          </Card>
          <Card className="border-l-4 border-l-red-500">
            <div className="text-gray-500 text-sm">Pengeluaran (Total)</div>
            <div className="text-2xl font-bold mt-1 text-red-600">Rp {totalExpense.toLocaleString('id-ID')}</div>
          </Card>
          <Card className="border-l-4 border-l-orange-500">
            <div className="text-gray-500 text-sm">Stok Menipis</div>
            <div className="text-2xl font-bold mt-1 text-orange-600">{lowStockItems.length} Item</div>
          </Card>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card title="Arus Kas 7 Hari Terakhir">
             <div className="h-64">
               <ResponsiveContainer width="100%" height="100%">
                 <BarChart data={data.financeTransactions.slice(0, 7)}>
                   <CartesianGrid strokeDasharray="3 3" />
                   <XAxis dataKey="date" />
                   <YAxis />
                   <Tooltip />
                   <Legend />
                   <Bar dataKey="amount" fill="#3b82f6" name="Nominal" />
                 </BarChart>
               </ResponsiveContainer>
             </div>
          </Card>
          <Card title="Stok Produk Terbanyak">
             <div className="h-64">
               <ResponsiveContainer width="100%" height="100%">
                 <BarChart data={data.products.sort((a,b) => b.stock - a.stock).slice(0, 5)} layout="vertical">
                   <CartesianGrid strokeDasharray="3 3" />
                   <XAxis type="number" />
                   <YAxis dataKey="name" type="category" width={100} />
                   <Tooltip />
                   <Bar dataKey="stock" fill="#10b981" name="Stok" />
                 </BarChart>
               </ResponsiveContainer>
             </div>
          </Card>
        </div>
      </div>
    );
  };

  const InputTransaksiView = () => {
    const [tab, setTab] = useState<'KEUANGAN' | 'MASUK' | 'KELUAR'>('KEUANGAN');
    
    // Keuangan State
    const [finType, setFinType] = useState<Types.TransactionType>(Types.TransactionType.INCOME);
    const [finAmount, setFinAmount] = useState('');
    const [finDesc, setFinDesc] = useState('');
    const [finAccount, setFinAccount] = useState('');

    // Inventori State
    const [invBarcode, setInvBarcode] = useState('');
    const [invProduct, setInvProduct] = useState<Types.Product | null>(null);
    const [invQty, setInvQty] = useState('');
    const [invNotes, setInvNotes] = useState('');
    const [invWarehouse, setInvWarehouse] = useState(data.warehouses[0]?.id || '');
    const [scannerOpen, setScannerOpen] = useState(false);

    // Handlers
    const handleSaveFinance = () => {
      if (!finAmount || !finAccount) return alert("Mohon lengkapi data");
      const newTx: Types.FinanceTransaction = {
        id: DB.generateId(),
        date: new Date().toISOString().split('T')[0],
        amount: Number(finAmount),
        type: finType,
        accountId: finAccount,
        description: finDesc,
        createdAt: Date.now()
      };
      data.financeTransactions.unshift(newTx);
      DB.saveAppData(data);
      DB.addLog('TRANSAKSI_KEUANGAN', user.id, `Input ${finType} Rp${finAmount}`);
      refreshData();
      setFinAmount(''); setFinDesc('');
      alert("Transaksi tersimpan!");
    };

    const handleSearchProduct = () => {
      const p = DB.getProductBySku(invBarcode);
      if (p) setInvProduct(p);
      else alert("Produk tidak ditemukan dengan barcode tersebut.");
    };

    const handleSaveInventory = (type: Types.InventoryType) => {
      if (!invProduct || !invQty) return alert("Pilih produk dan masukkan jumlah");
      const newTx: Types.InventoryTransaction = {
        id: DB.generateId(),
        date: new Date().toISOString().split('T')[0],
        type,
        productId: invProduct.id,
        warehouseId: invWarehouse,
        quantity: Number(invQty),
        reference: '-',
        notes: invNotes,
        createdAt: Date.now()
      };
      data.inventoryTransactions.unshift(newTx);
      
      // Update stock
      const prodIdx = data.products.findIndex(p => p.id === invProduct.id);
      if (prodIdx > -1) {
        if (type === Types.InventoryType.IN) data.products[prodIdx].stock += Number(invQty);
        else data.products[prodIdx].stock -= Number(invQty);
      }
      
      DB.saveAppData(data);
      DB.addLog('TRANSAKSI_BARANG', user.id, `${type} ${invQty} ${invProduct.name}`);
      refreshData();
      setInvQty(''); setInvNotes(''); setInvProduct(null); setInvBarcode('');
      alert("Transaksi barang tersimpan!");
    };

    return (
      <div className="space-y-6">
        <SectionHeader title="Input Transaksi" subtitle="Pilih jenis transaksi yang ingin diproses" />
        
        <div className="flex gap-2 border-b border-gray-200 mb-6">
          {['KEUANGAN', 'MASUK', 'KELUAR'].map((t) => (
            <button 
              key={t}
              onClick={() => setTab(t as any)}
              className={`px-4 py-2 font-medium ${tab === t ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500 hover:text-gray-700'}`}
            >
              {t === 'KEUANGAN' ? 'Keuangan' : t === 'MASUK' ? 'Barang Masuk' : 'Barang Keluar'}
            </button>
          ))}
        </div>

        {tab === 'KEUANGAN' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card title="Form Keuangan">
              <div className="space-y-4">
                <div className="flex gap-4">
                  <label className="flex items-center gap-2">
                    <input type="radio" checked={finType === Types.TransactionType.INCOME} onChange={() => setFinType(Types.TransactionType.INCOME)} /> Pemasukan
                  </label>
                  <label className="flex items-center gap-2">
                    <input type="radio" checked={finType === Types.TransactionType.EXPENSE} onChange={() => setFinType(Types.TransactionType.EXPENSE)} /> Pengeluaran
                  </label>
                </div>
                <Select label="Akun" value={finAccount} onChange={e => setFinAccount(e.target.value)}>
                  <option value="">-- Pilih Akun --</option>
                  {data.accounts.filter(a => a.type === finType).map(a => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </Select>
                <Input label="Nominal (Rp)" type="number" value={finAmount} onChange={e => setFinAmount(e.target.value)} />
                <Input label="Keterangan" value={finDesc} onChange={e => setFinDesc(e.target.value)} />
                <Button onClick={handleSaveFinance} className="w-full">Simpan Transaksi</Button>
              </div>
            </Card>
            <Card title="Riwayat Terakhir">
              <div className="overflow-auto max-h-80">
                <table className="w-full text-sm text-left">
                  <thead className="bg-gray-50 text-gray-700"><tr><th className="p-3">Tanggal</th><th className="p-3">Ket</th><th className="p-3">Nominal</th></tr></thead>
                  <tbody>
                    {data.financeTransactions.slice(0, 5).map(t => (
                      <tr key={t.id} className="border-b">
                        <td className="p-3">{t.date}</td>
                        <td className="p-3">{t.description}</td>
                        <td className={`p-3 font-medium ${t.type === Types.TransactionType.INCOME ? 'text-green-600' : 'text-red-600'}`}>
                          {t.type === Types.TransactionType.INCOME ? '+' : '-'} Rp{t.amount.toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          </div>
        )}

        {(tab === 'MASUK' || tab === 'KELUAR') && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card title={`Form Barang ${tab === 'MASUK' ? 'Masuk' : 'Keluar'}`}>
              <div className="space-y-4">
                <Select label="Lokasi Gudang" value={invWarehouse} onChange={e => setInvWarehouse(e.target.value)}>
                  {data.warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
                </Select>
                
                <div className="flex gap-2 items-end">
                  <Input 
                    label="Scan Barcode / SKU" 
                    value={invBarcode} 
                    onChange={e => setInvBarcode(e.target.value)} 
                    placeholder="Scan atau ketik SKU"
                    onKeyDown={(e) => e.key === 'Enter' && handleSearchProduct()}
                  />
                  <Button variant="secondary" onClick={() => setScannerOpen(true)}><Scan size={20} /></Button>
                  <Button onClick={handleSearchProduct}><Search size={20} /></Button>
                </div>

                {invProduct && (
                  <div className="p-4 bg-blue-50 rounded-lg border border-blue-100">
                    <p className="font-bold text-blue-800">{invProduct.name}</p>
                    <p className="text-sm text-blue-600">Stok saat ini: {invProduct.stock} {invProduct.unit}</p>
                    <p className="text-sm text-blue-600">Harga: Rp {invProduct.price.toLocaleString()}</p>
                  </div>
                )}

                <Input label="Jumlah" type="number" value={invQty} onChange={e => setInvQty(e.target.value)} />
                <Input label="Catatan" value={invNotes} onChange={e => setInvNotes(e.target.value)} />
                
                <Button 
                  onClick={() => handleSaveInventory(tab === 'MASUK' ? Types.InventoryType.IN : Types.InventoryType.OUT)} 
                  variant={tab === 'MASUK' ? 'success' : 'danger'}
                  className="w-full"
                >
                  {tab === 'MASUK' ? 'Terima Barang' : 'Keluarkan Barang'}
                </Button>
              </div>
            </Card>
            
            <Card title="Riwayat Terakhir">
               <div className="overflow-auto max-h-80">
                <table className="w-full text-sm text-left">
                  <thead className="bg-gray-50 text-gray-700"><tr><th className="p-3">Tanggal</th><th className="p-3">Item</th><th className="p-3">Qty</th></tr></thead>
                  <tbody>
                    {data.inventoryTransactions.filter(t => t.type === (tab === 'MASUK' ? Types.InventoryType.IN : Types.InventoryType.OUT)).slice(0, 5).map(t => {
                      const p = data.products.find(p => p.id === t.productId);
                      return (
                        <tr key={t.id} className="border-b">
                          <td className="p-3">{t.date}</td>
                          <td className="p-3">{p?.name || 'Unknown'}</td>
                          <td className="p-3 font-bold">{t.quantity}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Card>
          </div>
        )}

        {scannerOpen && (
          <BarcodeScanner 
            onScan={(code) => { setInvBarcode(code); handleSearchProduct(); setScannerOpen(false); }} 
            onClose={() => setScannerOpen(false)} 
          />
        )}
      </div>
    );
  };

  const DataBarangView = () => {
    const [searchTerm, setSearchTerm] = useState('');
    const [isModalOpen, setModalOpen] = useState(false);
    const [editItem, setEditItem] = useState<Partial<Types.Product>>({});

    const filtered = data.products.filter(p => p.name.toLowerCase().includes(searchTerm.toLowerCase()) || p.sku.includes(searchTerm));

    const handleSave = () => {
      if (!editItem.name || !editItem.sku) return alert("Nama dan SKU wajib diisi");
      if (editItem.id) {
        // Edit
        const idx = data.products.findIndex(p => p.id === editItem.id);
        data.products[idx] = { ...data.products[idx], ...editItem as Types.Product };
        DB.addLog('UPDATE_PRODUK', user.id, `Update ${editItem.name}`);
      } else {
        // Add
        const newProduct: Types.Product = {
          id: DB.generateId(),
          sku: editItem.sku || '',
          name: editItem.name || '',
          category: editItem.category || 'Umum',
          unit: editItem.unit || 'pcs',
          price: Number(editItem.price) || 0,
          cost: Number(editItem.cost) || 0,
          stock: Number(editItem.stock) || 0,
        };
        data.products.push(newProduct);
        DB.addLog('TAMBAH_PRODUK', user.id, `Add ${newProduct.name}`);
      }
      DB.saveAppData(data);
      refreshData();
      setModalOpen(false);
    };

    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <SectionHeader title="Data Barang" subtitle="Manajemen stok dan harga barang" />
          <Button onClick={() => { setEditItem({}); setModalOpen(true); }}><Plus size={18} /> Tambah Barang</Button>
        </div>

        <div className="flex gap-4 mb-4">
          <Input placeholder="Cari nama barang atau SKU..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)} className="max-w-md" />
        </div>

        <Card className="overflow-hidden">
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="p-4">SKU</th>
                <th className="p-4">Nama Barang</th>
                <th className="p-4">Kategori</th>
                <th className="p-4">Stok</th>
                <th className="p-4 text-right">Harga Jual</th>
                <th className="p-4 text-center">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(p => (
                <tr key={p.id} className="border-b hover:bg-gray-50">
                  <td className="p-4 font-mono text-gray-600">{p.sku}</td>
                  <td className="p-4 font-medium">{p.name}</td>
                  <td className="p-4"><span className="px-2 py-1 bg-gray-100 rounded text-xs">{p.category}</span></td>
                  <td className={`p-4 font-bold ${p.stock < 10 ? 'text-red-500' : 'text-green-600'}`}>{p.stock} {p.unit}</td>
                  <td className="p-4 text-right">Rp {p.price.toLocaleString()}</td>
                  <td className="p-4 text-center">
                    <button onClick={() => { setEditItem(p); setModalOpen(true); }} className="text-blue-600 hover:underline">Edit</button>
                  </td>
                </tr>
              ))}
              {filtered.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-gray-500">Data tidak ditemukan</td></tr>}
            </tbody>
          </table>
        </Card>

        <Modal isOpen={isModalOpen} onClose={() => setModalOpen(false)} title={editItem.id ? "Edit Barang" : "Tambah Barang Baru"}>
          <div className="space-y-4">
            <Input label="SKU / Barcode" value={editItem.sku || ''} onChange={e => setEditItem({...editItem, sku: e.target.value})} />
            <Input label="Nama Barang" value={editItem.name || ''} onChange={e => setEditItem({...editItem, name: e.target.value})} />
            <div className="grid grid-cols-2 gap-4">
              <Input label="Kategori" value={editItem.category || ''} onChange={e => setEditItem({...editItem, category: e.target.value})} />
              <Input label="Satuan" value={editItem.unit || ''} onChange={e => setEditItem({...editItem, unit: e.target.value})} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input label="Harga Beli" type="number" value={editItem.cost} onChange={e => setEditItem({...editItem, cost: Number(e.target.value)})} />
              <Input label="Harga Jual" type="number" value={editItem.price} onChange={e => setEditItem({...editItem, price: Number(e.target.value)})} />
            </div>
            <Input label="Stok Awal" type="number" value={editItem.stock} onChange={e => setEditItem({...editItem, stock: Number(e.target.value)})} disabled={!!editItem.id} />
            <Button onClick={handleSave} className="w-full">Simpan Data</Button>
          </div>
        </Modal>
      </div>
    );
  };

  const FinanceDashboard = () => {
    // Aggregation logic
    const totalIncome = data.financeTransactions.filter(t => t.type === Types.TransactionType.INCOME).reduce((a,b) => a + b.amount, 0);
    const totalExpense = data.financeTransactions.filter(t => t.type === Types.TransactionType.EXPENSE).reduce((a,b) => a + b.amount, 0);
    const profit = totalIncome - totalExpense;

    const dataPie = [
      { name: 'Pemasukan', value: totalIncome },
      { name: 'Pengeluaran', value: totalExpense },
    ];
    const COLORS = ['#10b981', '#ef4444'];

    return (
       <div className="space-y-6">
        <SectionHeader title="Dashboard Keuangan" subtitle="Ringkasan performa finansial" />
        
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
           <Card className="bg-green-50 border-green-200">
             <div className="text-green-700 font-medium">Total Pemasukan</div>
             <div className="text-3xl font-bold text-green-800 mt-2">Rp {totalIncome.toLocaleString()}</div>
           </Card>
           <Card className="bg-red-50 border-red-200">
             <div className="text-red-700 font-medium">Total Pengeluaran</div>
             <div className="text-3xl font-bold text-red-800 mt-2">Rp {totalExpense.toLocaleString()}</div>
           </Card>
           <Card className="bg-blue-50 border-blue-200">
             <div className="text-blue-700 font-medium">Laba Bersih</div>
             <div className="text-3xl font-bold text-blue-800 mt-2">Rp {profit.toLocaleString()}</div>
           </Card>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card title="Komposisi Keuangan">
            <div className="h-64 flex justify-center">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={dataPie} cx="50%" cy="50%" innerRadius={60} outerRadius={80} paddingAngle={5} dataKey="value">
                    {dataPie.map((entry, index) => <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />)}
                  </Pie>
                  <Tooltip formatter={(value: number) => `Rp ${value.toLocaleString()}`} />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </div>
          </Card>
          
          <Card title="Laporan Singkat">
             <ul className="space-y-4">
               <li className="flex justify-between border-b pb-2">
                 <span>Modal Awal</span>
                 <span className="font-medium">Rp {data.settings.initialBalance.toLocaleString()}</span>
               </li>
               <li className="flex justify-between border-b pb-2 text-green-600">
                 <span>+ Pendapatan Operasional</span>
                 <span className="font-medium">Rp {totalIncome.toLocaleString()}</span>
               </li>
               <li className="flex justify-between border-b pb-2 text-red-600">
                 <span>- Beban Operasional</span>
                 <span className="font-medium">Rp {totalExpense.toLocaleString()}</span>
               </li>
               <li className="flex justify-between pt-2 text-lg font-bold">
                 <span>Saldo Akhir</span>
                 <span>Rp {(data.settings.initialBalance + profit).toLocaleString()}</span>
               </li>
             </ul>
          </Card>
        </div>
      </div>
    );
  };

  const SettingsView = () => {
    const [settings, setSettings] = useState(data.settings);
    
    const handleSave = () => {
      const newData = { ...data, settings };
      DB.saveAppData(newData);
      refreshData();
      alert("Pengaturan perusahaan disimpan.");
    };

    const handleBackup = () => {
      const jsonString = `data:text/json;chatset=utf-8,${encodeURIComponent(JSON.stringify(data))}`;
      const link = document.createElement("a");
      link.href = jsonString;
      link.download = `backup-siki-${new Date().toISOString().slice(0,10)}.json`;
      link.click();
    };

    const handleRestore = (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (evt) => {
        try {
          const parsed = JSON.parse(evt.target?.result as string);
          DB.saveAppData(parsed);
          refreshData();
          alert("Data berhasil dipulihkan!");
        } catch (err) {
          alert("File backup tidak valid.");
        }
      };
      reader.readAsText(file);
    };

    const handleReset = () => {
      if (confirm("PERINGATAN: Semua data akan dihapus permanen. Lanjutkan?")) {
        DB.resetAppData();
      }
    };

    return (
      <div className="space-y-6">
        <SectionHeader title="Pengaturan & Utilitas" subtitle="Konfigurasi sistem dan manajemen data" />
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card title="Profil Perusahaan">
            <div className="space-y-4">
              <Input label="Nama Perusahaan" value={settings.name} onChange={e => setSettings({...settings, name: e.target.value})} />
              <Input label="Alamat" value={settings.address} onChange={e => setSettings({...settings, address: e.target.value})} />
              <Input 
                label="Modal Awal (Saldo)" 
                type="number" 
                value={settings.initialBalance} 
                onChange={e => setSettings({...settings, initialBalance: Number(e.target.value)})} 
              />
              <Button onClick={handleSave} className="w-full mt-4">Simpan Pengaturan</Button>
            </div>
          </Card>

          <Card title="Manajemen Data">
            <div className="space-y-4">
              <div className="p-4 bg-yellow-50 text-yellow-800 rounded-lg text-sm">
                <p className="font-bold flex items-center gap-2"><AlertTriangle size={16}/> Perhatian</p>
                <p>Lakukan backup secara berkala. Restore akan menimpa data yang ada.</p>
              </div>
              
              <Button onClick={handleBackup} variant="secondary" className="w-full">
                <Download size={18} /> Backup Data (JSON)
              </Button>
              
              <div className="relative">
                 <input type="file" accept=".json" onChange={handleRestore} className="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                 <Button variant="secondary" className="w-full">
                    <Upload size={18} /> Restore Data
                 </Button>
              </div>

              <hr className="my-4" />
              
              <Button onClick={handleReset} variant="danger" className="w-full">
                <Trash2 size={18} /> Reset Aplikasi (Hapus Data)
              </Button>
            </div>
          </Card>
        </div>
      </div>
    );
  };

  // --- Additional Views Implementation ---

  const InventoryWarehouseView = () => {
    const [name, setName] = useState('');
    const [location, setLocation] = useState('');

    const handleAdd = () => {
      if(!name) return;
      data.warehouses.push({ id: DB.generateId(), name, location });
      DB.saveAppData(data);
      refreshData();
      setName(''); setLocation('');
    };

    return (
      <div className="space-y-6">
        <SectionHeader title="Data Gudang" subtitle="Kelola lokasi penyimpanan" />
        <Card title="Tambah Gudang">
          <div className="flex gap-4 items-end">
            <Input label="Nama Gudang" value={name} onChange={e => setName(e.target.value)} />
            <Input label="Lokasi" value={location} onChange={e => setLocation(e.target.value)} />
            <Button onClick={handleAdd}>Tambah</Button>
          </div>
        </Card>
        <Card title="Daftar Gudang">
          <table className="w-full text-left text-sm">
            <thead><tr className="border-b bg-gray-50"><th className="p-3">Nama</th><th className="p-3">Lokasi</th></tr></thead>
            <tbody>
              {data.warehouses.map(w => (
                <tr key={w.id} className="border-b"><td className="p-3 font-medium">{w.name}</td><td className="p-3">{w.location}</td></tr>
              ))}
            </tbody>
          </table>
        </Card>
      </div>
    );
  };

  const InventoryHistoryView = () => {
    return (
      <div className="space-y-6">
        <SectionHeader title="Riwayat Barang" subtitle="Laporan keluar masuk barang" />
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="p-3">Tanggal</th>
                  <th className="p-3">Tipe</th>
                  <th className="p-3">Barang</th>
                  <th className="p-3">Jumlah</th>
                  <th className="p-3">Gudang</th>
                  <th className="p-3">Catatan</th>
                </tr>
              </thead>
              <tbody>
                {data.inventoryTransactions.map(t => {
                   const p = data.products.find(x => x.id === t.productId);
                   const w = data.warehouses.find(x => x.id === t.warehouseId);
                   return (
                    <tr key={t.id} className="border-b hover:bg-gray-50">
                      <td className="p-3">{t.date}</td>
                      <td className="p-3"><span className={`px-2 py-1 rounded text-xs ${t.type === 'IN' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>{t.type === 'IN' ? 'MASUK' : 'KELUAR'}</span></td>
                      <td className="p-3 font-medium">{p?.name || '-'}</td>
                      <td className="p-3">{t.quantity}</td>
                      <td className="p-3">{w?.name || '-'}</td>
                      <td className="p-3 text-gray-500">{t.notes}</td>
                    </tr>
                   );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    );
  };

  const FinanceAccountsView = () => {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [type, setType] = useState<Types.TransactionType>(Types.TransactionType.EXPENSE);

    const handleAdd = () => {
      if(!name || !code) return;
      data.accounts.push({ id: DB.generateId(), name, code, type });
      DB.saveAppData(data);
      refreshData();
      setName(''); setCode('');
    };

    return (
      <div className="space-y-6">
        <SectionHeader title="Akun Keuangan" subtitle="Kelola kategori pemasukan dan pengeluaran" />
        <Card title="Tambah Akun">
          <div className="flex gap-4 items-end flex-wrap">
             <div className="w-32"><Input label="Kode Akun" value={code} onChange={e => setCode(e.target.value)} /></div>
             <div className="flex-1"><Input label="Nama Akun" value={name} onChange={e => setName(e.target.value)} /></div>
             <div className="w-40">
               <Select label="Tipe" value={type} onChange={e => setType(e.target.value as any)}>
                 <option value={Types.TransactionType.EXPENSE}>Pengeluaran</option>
                 <option value={Types.TransactionType.INCOME}>Pemasukan</option>
               </Select>
             </div>
             <Button onClick={handleAdd}>Tambah</Button>
          </div>
        </Card>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
           <Card title="Akun Pemasukan">
              <ul className="space-y-2">
                {data.accounts.filter(a => a.type === Types.TransactionType.INCOME).map(a => (
                  <li key={a.id} className="flex justify-between p-2 bg-gray-50 rounded"><span>{a.code} - {a.name}</span></li>
                ))}
              </ul>
           </Card>
           <Card title="Akun Pengeluaran">
              <ul className="space-y-2">
                {data.accounts.filter(a => a.type === Types.TransactionType.EXPENSE).map(a => (
                  <li key={a.id} className="flex justify-between p-2 bg-gray-50 rounded"><span>{a.code} - {a.name}</span></li>
                ))}
              </ul>
           </Card>
        </div>
      </div>
    );
  };

  const FinanceTransactionsView = () => {
    return (
      <div className="space-y-6">
        <SectionHeader title="Riwayat Keuangan" subtitle="Laporan semua transaksi keuangan" />
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="p-3">Tanggal</th>
                  <th className="p-3">Akun</th>
                  <th className="p-3">Deskripsi</th>
                  <th className="p-3 text-right">Debit (Masuk)</th>
                  <th className="p-3 text-right">Kredit (Keluar)</th>
                </tr>
              </thead>
              <tbody>
                {data.financeTransactions.map(t => {
                   const acc = data.accounts.find(x => x.id === t.accountId);
                   return (
                    <tr key={t.id} className="border-b hover:bg-gray-50">
                      <td className="p-3">{t.date}</td>
                      <td className="p-3 text-gray-600">{acc?.code} - {acc?.name}</td>
                      <td className="p-3">{t.description}</td>
                      <td className="p-3 text-right text-green-600">{t.type === 'INCOME' ? `Rp ${t.amount.toLocaleString()}` : '-'}</td>
                      <td className="p-3 text-right text-red-600">{t.type === 'EXPENSE' ? `Rp ${t.amount.toLocaleString()}` : '-'}</td>
                    </tr>
                   );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    );
  };

  const UsersView = () => {
    const [newUser, setNewUser] = useState<Partial<Types.User>>({ role: Types.UserRole.ADMIN_GUDANG });
    const [isModalOpen, setModalOpen] = useState(false);

    const handleSave = () => {
      if(!newUser.username || !newUser.password || !newUser.email) return;
      data.users.push({
        id: DB.generateId(),
        username: newUser.username,
        email: newUser.email,
        password: newUser.password,
        role: newUser.role as Types.UserRole
      });
      DB.saveAppData(data);
      refreshData();
      setModalOpen(false);
      setNewUser({ role: Types.UserRole.ADMIN_GUDANG });
    };

    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <SectionHeader title="Manajemen Pengguna" subtitle="Kelola akses aplikasi" />
          <Button onClick={() => setModalOpen(true)}><Plus size={18}/> Tambah User</Button>
        </div>
        <Card>
          <table className="w-full text-left text-sm">
            <thead className="bg-gray-50 border-b">
               <tr>
                 <th className="p-3">Username</th>
                 <th className="p-3">Email</th>
                 <th className="p-3">Role</th>
                 <th className="p-3">Action</th>
               </tr>
            </thead>
            <tbody>
              {data.users.map(u => (
                <tr key={u.id} className="border-b">
                  <td className="p-3 font-medium">{u.username}</td>
                  <td className="p-3">{u.email}</td>
                  <td className="p-3"><span className="bg-gray-100 px-2 py-1 rounded text-xs">{u.role}</span></td>
                  <td className="p-3 text-gray-400 text-xs">Edit not available in demo</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>

        <Modal isOpen={isModalOpen} onClose={() => setModalOpen(false)} title="Tambah User Baru">
           <div className="space-y-4">
             <Input label="Username" value={newUser.username || ''} onChange={e => setNewUser({...newUser, username: e.target.value})} />
             <Input label="Email" type="email" value={newUser.email || ''} onChange={e => setNewUser({...newUser, email: e.target.value})} />
             <Input label="Password" type="password" value={newUser.password || ''} onChange={e => setNewUser({...newUser, password: e.target.value})} />
             <Select label="Role" value={newUser.role} onChange={e => setNewUser({...newUser, role: e.target.value as any})}>
                <option value={Types.UserRole.ADMIN_GUDANG}>Admin Gudang</option>
                <option value={Types.UserRole.ADMIN_KEUANGAN}>Admin Keuangan</option>
                <option value={Types.UserRole.SUPER_ADMIN}>Super Admin</option>
             </Select>
             <Button onClick={handleSave} className="w-full">Simpan User</Button>
           </div>
        </Modal>
      </div>
    );
  };

  const LogsView = () => {
    return (
      <div className="space-y-6">
        <SectionHeader title="System Logs" subtitle="Catatan aktivitas sistem" />
        <Card>
           <div className="overflow-auto max-h-[600px]">
             <table className="w-full text-left text-sm">
               <thead className="bg-gray-50 border-b sticky top-0">
                 <tr>
                   <th className="p-3">Waktu</th>
                   <th className="p-3">User ID</th>
                   <th className="p-3">Aksi</th>
                   <th className="p-3">Detail</th>
                 </tr>
               </thead>
               <tbody>
                 {data.logs.map(log => (
                   <tr key={log.id} className="border-b hover:bg-gray-50">
                     <td className="p-3 whitespace-nowrap">{new Date(log.timestamp).toLocaleString()}</td>
                     <td className="p-3 font-mono text-xs">{log.userId}</td>
                     <td className="p-3 font-medium text-blue-600">{log.action}</td>
                     <td className="p-3 text-gray-600">{log.details}</td>
                   </tr>
                 ))}
               </tbody>
             </table>
           </div>
        </Card>
      </div>
    );
  };

  // --- Main Render ---

  return (
    <div className="flex h-screen bg-gray-100 font-sans text-gray-800">
       {/* Sidebar */}
       <aside className={`bg-white shadow-xl z-20 transition-all duration-300 flex flex-col ${isSidebarOpen ? 'w-64' : 'w-0 overflow-hidden'}`}>
         {/* Logo/Header */}
         <div className="p-6 border-b flex items-center gap-3">
            <div className="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">S</div>
            <div>
              <h1 className="font-bold text-lg leading-tight">SIKI</h1>
              <p className="text-xs text-gray-500">Finance & Inventory</p>
            </div>
         </div>

         {/* Menu */}
         <div className="flex-1 overflow-y-auto py-4">
            <div className="px-4 mb-2 text-xs font-semibold text-gray-400 uppercase">Menu Utama</div>
            <NavItem icon={LayoutDashboard} label="Dashboard" active={view === View.DASHBOARD} onClick={() => setView(View.DASHBOARD)} />
            <NavItem icon={Plus} label="Input Transaksi" active={view === View.INPUT_TRANSAKSI} onClick={() => setView(View.INPUT_TRANSAKSI)} />
            
            <div className="px-4 mt-6 mb-2 text-xs font-semibold text-gray-400 uppercase">Inventori</div>
            <NavItem icon={Package} label="Data Barang" active={view === View.INVENTORY_ITEMS} onClick={() => setView(View.INVENTORY_ITEMS)} />
            <NavItem icon={Archive} label="Data Gudang" active={view === View.INVENTORY_WAREHOUSE} onClick={() => setView(View.INVENTORY_WAREHOUSE)} />
            <NavItem icon={History} label="Riwayat Barang" active={view === View.INVENTORY_REPORT} onClick={() => setView(View.INVENTORY_REPORT)} />

            <div className="px-4 mt-6 mb-2 text-xs font-semibold text-gray-400 uppercase">Keuangan</div>
            <NavItem icon={TrendingUp} label="Dashboard Keuangan" active={view === View.FINANCE_DASHBOARD} onClick={() => setView(View.FINANCE_DASHBOARD)} />
            <NavItem icon={Database} label="Data Akun" active={view === View.FINANCE_ACCOUNTS} onClick={() => setView(View.FINANCE_ACCOUNTS)} />
            <NavItem icon={FileText} label="Riwayat Transaksi" active={view === View.FINANCE_TRANSACTIONS} onClick={() => setView(View.FINANCE_TRANSACTIONS)} />

            <div className="px-4 mt-6 mb-2 text-xs font-semibold text-gray-400 uppercase">Admin</div>
            {user.role === Types.UserRole.SUPER_ADMIN && (
              <NavItem icon={Users} label="Pengguna" active={view === View.USERS} onClick={() => setView(View.USERS)} />
            )}
            <NavItem icon={Settings} label="Pengaturan" active={view === View.SETTINGS} onClick={() => setView(View.SETTINGS)} />
            <NavItem icon={AlertTriangle} label="System Logs" active={view === View.LOGS} onClick={() => setView(View.LOGS)} />
         </div>

         {/* Footer */}
         <div className="p-4 border-t bg-gray-50">
            <div className="flex items-center gap-3 mb-3">
              <div className="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold">
                {user.username.charAt(0).toUpperCase()}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{user.username}</p>
                <p className="text-xs text-gray-500 truncate">{user.role}</p>
              </div>
            </div>
            <Button variant="secondary" className="w-full text-xs" onClick={handleLogout}>
              <LogOut size={14} /> Keluar
            </Button>
         </div>
       </aside>

       {/* Main Content */}
       <div className="flex-1 flex flex-col h-screen overflow-hidden">
         {/* Topbar */}
         <header className="h-16 bg-white border-b shadow-sm flex items-center px-6 justify-between">
            <button onClick={() => setSidebarOpen(!isSidebarOpen)} className="p-2 hover:bg-gray-100 rounded-lg">
              <Menu size={20} />
            </button>
            <div className="font-semibold text-gray-700">
               {data.settings.name}
            </div>
         </header>

         {/* Content Scrollable */}
         <main className="flex-1 overflow-y-auto p-6">
            {view === View.DASHBOARD && <DashboardHome />}
            {view === View.INPUT_TRANSAKSI && <InputTransaksiView />}
            {view === View.INVENTORY_ITEMS && <DataBarangView />}
            {view === View.INVENTORY_WAREHOUSE && <InventoryWarehouseView />}
            {view === View.INVENTORY_REPORT && <InventoryHistoryView />}
            {view === View.FINANCE_DASHBOARD && <FinanceDashboard />}
            {view === View.FINANCE_ACCOUNTS && <FinanceAccountsView />}
            {view === View.FINANCE_TRANSACTIONS && <FinanceTransactionsView />}
            {view === View.USERS && <UsersView />}
            {view === View.SETTINGS && <SettingsView />}
            {view === View.LOGS && <LogsView />}
         </main>
       </div>
    </div>
  );
}
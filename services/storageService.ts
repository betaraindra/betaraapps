import { AppData, User, UserRole, CompanySettings, Product, Warehouse, Account, TransactionType, FinanceTransaction, InventoryTransaction, InventoryType, SystemLog } from '../types';

const STORAGE_KEY = 'SIKI_DATA_V1';

const INITIAL_DATA: AppData = {
  users: [
    { id: '1', username: 'superadmin', email: 'super@admin.com', password: 'admin', role: UserRole.SUPER_ADMIN },
    { id: '2', username: 'gudang', email: 'gudang@admin.com', password: 'admin', role: UserRole.ADMIN_GUDANG },
    { id: '3', username: 'keuangan', email: 'keuangan@admin.com', password: 'admin', role: UserRole.ADMIN_KEUANGAN },
  ],
  accounts: [
    { id: '1', code: '4001', name: 'Penjualan Produk', type: TransactionType.INCOME },
    { id: '2', code: '5001', name: 'Pembelian Stok', type: TransactionType.EXPENSE },
    { id: '3', code: '5002', name: 'Biaya Operasional', type: TransactionType.EXPENSE },
    { id: '4', code: '4002', name: 'Pendapatan Lain-lain', type: TransactionType.INCOME },
  ],
  warehouses: [
    { id: '1', name: 'Gudang Utama', location: 'Jakarta Pusat' },
  ],
  products: [
    { id: '1', sku: '899123456', name: 'Contoh Produk A', category: 'Elektronik', unit: 'pcs', price: 150000, cost: 100000, stock: 50 },
    { id: '2', sku: '899987654', name: 'Contoh Produk B', category: 'ATK', unit: 'pack', price: 25000, cost: 15000, stock: 100 },
  ],
  financeTransactions: [],
  inventoryTransactions: [],
  settings: {
    name: 'PT. Maju Jaya Sejahtera',
    address: 'Jl. Jendral Sudirman No. 1, Jakarta',
    logoUrl: '',
    initialBalance: 10000000,
  },
  logs: [],
};

export const getAppData = (): AppData => {
  const data = localStorage.getItem(STORAGE_KEY);
  return data ? JSON.parse(data) : INITIAL_DATA;
};

export const saveAppData = (data: AppData) => {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
};

export const resetAppData = () => {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(INITIAL_DATA));
  window.location.reload();
};

// Helper for generating IDs
export const generateId = () => Math.random().toString(36).substr(2, 9);

// Logging
export const addLog = (action: string, userId: string, details: string) => {
  const data = getAppData();
  const newLog: SystemLog = {
    id: generateId(),
    timestamp: Date.now(),
    action,
    userId,
    details
  };
  data.logs.unshift(newLog); // Add to beginning
  saveAppData(data);
};

// Data Helpers
export const getProductBySku = (sku: string): Product | undefined => {
  const data = getAppData();
  return data.products.find(p => p.sku === sku);
};

export const updateProductStock = (productId: string, qtyChange: number, type: InventoryType) => {
  const data = getAppData();
  const productIndex = data.products.findIndex(p => p.id === productId);
  if (productIndex > -1) {
    const change = type === InventoryType.IN ? qtyChange : -qtyChange;
    data.products[productIndex].stock += change;
    saveAppData(data);
  }
};
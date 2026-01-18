export enum UserRole {
  SUPER_ADMIN = 'SUPER_ADMIN',
  ADMIN_GUDANG = 'ADMIN_GUDANG',
  ADMIN_KEUANGAN = 'ADMIN_KEUANGAN',
}

export interface User {
  id: string;
  username: string;
  password?: string; // stored plainly for this local demo, ideally hashed
  email: string;
  role: UserRole;
}

export enum TransactionType {
  INCOME = 'INCOME',
  EXPENSE = 'EXPENSE',
}

export interface Account {
  id: string;
  code: string;
  name: string;
  type: TransactionType;
}

export interface FinanceTransaction {
  id: string;
  date: string;
  amount: number;
  type: TransactionType;
  accountId: string;
  description: string;
  createdAt: number;
}

export interface Warehouse {
  id: string;
  name: string;
  location: string;
}

export interface Product {
  id: string;
  sku: string;
  name: string;
  category: string;
  unit: string;
  price: number; // Selling price
  cost: number; // Buying cost
  stock: number;
}

export enum InventoryType {
  IN = 'IN',
  OUT = 'OUT',
}

export interface InventoryTransaction {
  id: string;
  date: string;
  type: InventoryType;
  productId: string;
  warehouseId: string;
  quantity: number;
  reference: string; // e.g., PO number or Invoice
  notes: string;
  createdAt: number;
}

export interface CompanySettings {
  name: string;
  address: string;
  logoUrl: string;
  initialBalance: number;
}

export interface SystemLog {
  id: string;
  timestamp: number;
  action: string;
  userId: string;
  details: string;
}

export interface AppData {
  users: User[];
  accounts: Account[];
  financeTransactions: FinanceTransaction[];
  warehouses: Warehouse[];
  products: Product[];
  inventoryTransactions: InventoryTransaction[];
  settings: CompanySettings;
  logs: SystemLog[];
}
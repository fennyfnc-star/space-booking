declare module "*.css";

interface SbConfig {
  apiBase: string;
  nonce: string;
  stripeKey: string;
  currency: string;
  symbol: string;
  dateFormat: string;
}

declare global {
  interface Window {
    sbConfig: SbConfig;
  }
}


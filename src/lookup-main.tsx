import React from 'react';
import { createRoot } from 'react-dom/client';
import { LookupApp } from './components/LookupApp';
import './styles.css';

const container = document.getElementById('sb-lookup-app');
if (container) {
  createRoot(container).render(
    <React.StrictMode>
      <LookupApp />
    </React.StrictMode>
  );
}

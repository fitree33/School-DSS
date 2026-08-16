import React from 'react';
import ReactDOM from 'react-dom/client';

import '../../css/spa.css';
import { AppProviders } from '@/app/AppProviders';

const root = document.getElementById('root');

if (!root) {
    throw new Error('School-DSS SPA root element was not found.');
}

ReactDOM.createRoot(root).render(
    <React.StrictMode>
        <AppProviders />
    </React.StrictMode>,
);

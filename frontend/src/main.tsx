import { createRoot } from 'react-dom/client'
import React from 'react'
import { MantineProvider } from '@mantine/core';
import { ModalsProvider } from '@mantine/modals';
import App from './App'

createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <MantineProvider
      theme={{
          fontFamily: '"Inter var", -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif',
          headings: {
            fontFamily: '"Inter var", -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif',
            sizes: {
              h1: { fontSize: '1.5rem' },
              h2: { fontSize: '1.25rem' },
              h3: { fontSize: '1.5rem' },
              h4: { fontSize: '0.875rem' },
              h5: { fontSize: '0.75rem' },
              h6: { fontSize: '0.625rem' },
            }
          },
          colors: {
            'tablrblue': ['#e1f2ff', '#b9d6f9', '#8ebbf0', '#64a0e7', '#3a85df', '#358ae8', '#206cc5', '#15549a', '#0b3c70', '#022446'],
            'apprgreen': ['#e9f8ed','#d4edda','#c9e8d1','#a7d8b3','#85c995','#63ba77','#4aa15d','#397d48','#295a33','#17361e'],
            'white': ['#ffffff','#ffffff','#ffffff','#ffffff','#ffffff','#ffffff','#ffffff','#ffffff','#ffffff','#ffffff'],
          },
          primaryColor: 'tablrblue',
          primaryShade: 6,
        }}
      >
        <ModalsProvider>
          <App />
        </ModalsProvider>
    </MantineProvider>
  </React.StrictMode>
)

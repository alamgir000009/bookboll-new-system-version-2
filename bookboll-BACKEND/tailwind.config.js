import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                sidebar: {
                    DEFAULT: '#1e3a8a',
                    dark: '#172554',
                    light: '#2563eb',
                },
                brand: {
                    gold: '#3b82f6',
                    'gold-light': '#60a5fa',
                    'gold-dark': '#1d4ed8',
                    dark: '#0B0B0F',
                    'dark-soft': '#14141C',
                    'dark-card': '#1C1C28',
                },
                surface: '#F0F4FA',
            },
            boxShadow: {
                glow: '0 0 40px rgba(37, 99, 235, 0.18)',
                card: '0 4px 24px rgba(0, 0, 0, 0.08)',
            },
        },
    },

    plugins: [forms],
};

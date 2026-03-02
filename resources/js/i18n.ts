import type { App } from 'vue';
import { createI18n } from 'vue-i18n';
import en from './locales/en.json';
import ja from './locales/ja.json';

export type MessageSchema = typeof en;

const i18n = createI18n({
    legacy: false,
    locale: 'ja', // デフォルトは日本語
    fallbackLocale: 'en',
    messages: {
        en,
        ja,
    },
});

export function setupI18n(app: App) {
    app.use(i18n);
}

export function getLocale() {
    return i18n.global.locale.value;
}

export function setLocale(locale: 'en' | 'ja') {
    i18n.global.locale.value = locale;
    // ローカルストレージに保存
    localStorage.setItem('locale', locale);
}

export function initializeLocale() {
    // ローカルストレージから設定を読み込み
    const savedLocale = localStorage.getItem('locale') as 'en' | 'ja' | null;
    if (savedLocale && ['en', 'ja'].includes(savedLocale)) {
        setLocale(savedLocale);
    } else {
        // ブラウザの言語設定を確認
        const browserLang = navigator.language.slice(0, 2);
        if (browserLang === 'ja') {
            setLocale('ja');
        } else {
            setLocale('en');
        }
    }
}

export { i18n };
export default i18n;

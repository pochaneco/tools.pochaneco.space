<script setup lang="ts">
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { dashboard, login } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    <div class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] lg:justify-center lg:p-8 dark:bg-[#0a0a0a]">
        <header class="mb-6 w-full max-w-[335px] text-sm not-has-[nav]:hidden lg:max-w-4xl">
            <nav class="flex items-center justify-between gap-4">
                <LanguageSwitcher />
                <div class="flex items-center gap-4">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        {{ t('welcome.dashboard') }}
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                        >
                            {{ t('welcome.login') }}
                        </Link>
                    </template>
                </div>
            </nav>
        </header>
        <div class="flex w-full items-center justify-center opacity-100 transition-opacity duration-750 lg:grow starting:opacity-0">
            <main class="flex w-full max-w-[335px] flex-col-reverse overflow-hidden rounded-lg lg:max-w-4xl lg:flex-row">
                <div
                    class="flex-1 rounded-br-lg rounded-bl-lg bg-white p-6 pb-12 text-[13px] leading-[20px] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:rounded-tl-lg lg:rounded-br-none lg:p-20 dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
                >
                    <h1 class="mb-4 text-2xl font-medium lg:text-3xl">{{ t('welcome.title') }}</h1>
                    <div class="mb-6 flex justify-center">
                        <div
                            class="flex h-32 w-32 items-center justify-center rounded-full bg-gradient-to-br from-orange-200 to-orange-200 dark:from-orange-300 dark:to-orange-500"
                        >
                            <span class="text-4xl">🐱</span>
                        </div>
                    </div>
                    <p class="mb-6 text-center text-base text-[#706f6c] lg:text-lg dark:text-[#A1A09A]">{{ t('welcome.subtitle') }}</p>
                    <div class="flex flex-col items-center text-center">
                        <a
                            href="https://pochaneco.space"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center space-x-2 rounded-sm border border-black bg-[#1b1b18] px-6 py-3 text-sm leading-normal text-white hover:border-black hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:border-white dark:hover:bg-white"
                        >
                            <span>{{ t('welcome.contact_link') }}</span>
                            <svg width="12" height="12" viewBox="0 0 10 11" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3">
                                <path d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001" stroke="currentColor" stroke-linecap="square" />
                            </svg>
                        </a>
                        <Link
                            :href="login()"
                            class="inline-block mt-3 rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                        >
                            {{ t('welcome.login') }}
                        </Link>
                    </div>
                </div>
                <div
                    class="relative -mb-px flex items-center justify-center w-full shrink-0 overflow-hidden rounded-t-lg bg-[#fdf8e4] lg:mb-0 lg:-ml-px lg:aspect-auto lg:w-[438px] lg:rounded-t-none lg:rounded-r-lg dark:bg-[#1D0002]"
                >
                    <img src="/images/pochaneco-tools.png" alt="ぽちゃねこロゴ" class="object-contain max-h-full" />
                </div>
            </main>
        </div>
        <div class="hidden h-14.5 lg:block"></div>
    </div>
</template>

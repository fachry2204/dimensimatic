import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // Local archived build directories contain obsolete copies of tests.
        include: ['src/**/*.test.ts'],
        maxWorkers: 4,
    },
});

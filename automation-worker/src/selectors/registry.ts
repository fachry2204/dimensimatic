import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { z } from "zod";
import type { Locator, Page } from "playwright";

const selectorSchema = z.discriminatedUnion("strategy", [
    z.object({
        strategy: z.literal("label"),
        value: z.string().min(1),
        exact: z.boolean().default(true),
    }),
    z.object({ strategy: z.literal("testId"), value: z.string().min(1) }),
    z.object({
        strategy: z.literal("role"),
        role: z.string().min(1),
        name: z.string().min(1),
        exact: z.boolean().default(true),
    }),
    z.object({
        strategy: z.literal("text"),
        value: z.string().min(1),
        exact: z.boolean().default(true),
    }),
    z.object({
        strategy: z.literal("css"),
        value: z
            .string()
            .regex(
                /^(?:#|\[data-[a-z0-9_-]+[=\]])/i,
                "CSS must start with an id or data-* attribute",
            ),
    }),
]);
const fieldSchema = z.object({
    selector: selectorSchema,
    read: z.enum(["text", "value", "href"]).default("value"),
});
const inputSchema = z.object({
    selector: selectorSchema,
    type: z.enum(["text", "select", "checkbox", "file"]).default("text"),
});
const registrySchema = z.object({
    soundfresh: z.object({
        configured: z.boolean(),
        releaseFields: z.record(z.string(), fieldSchema),
        tracksContainer: selectorSchema.optional(),
        trackFields: z.record(z.string(), fieldSchema).default({}),
    }),
    soundon: z.object({
        configured: z.boolean(),
        createButton: selectorSchema.optional(),
        saveDraftButton: selectorSchema.optional(),
        fields: z.record(z.string(), inputSchema),
        draftIdSelector: fieldSchema.optional(),
    }),
});
export type SelectorSpec = z.infer<typeof selectorSchema>;
export type Registry = z.infer<typeof registrySchema>;
export async function loadRegistry(): Promise<Registry> {
    const path =
        process.env.SELECTOR_REGISTRY_PATH ??
        resolve("config", "selectors.json");
    return registrySchema.parse(JSON.parse(await readFile(path, "utf8")));
}
export function locate(root: Page | Locator, spec: SelectorSpec): Locator {
    switch (spec.strategy) {
        case "label":
            return root.getByLabel(spec.value, { exact: spec.exact });
        case "testId":
            return root.getByTestId(spec.value);
        case "role":
            return root.getByRole(spec.role as any, {
                name: spec.name,
                exact: spec.exact,
            });
        case "text":
            return root.getByText(spec.value, { exact: spec.exact });
        case "css":
            return root.locator(spec.value);
    }
}
export async function readField(
    root: Page | Locator,
    field: z.infer<typeof fieldSchema>,
): Promise<string> {
    const locator = locate(root, field.selector);
    if ((await locator.count()) !== 1) throw new Error("SELECTOR_NOT_UNIQUE");
    if (field.read === "href")
        return (await locator.getAttribute("href")) ?? "";
    if (field.read === "text")
        return ((await locator.textContent()) ?? "").trim();
    return await locator.inputValue();
}
export function valueAt(source: any, path: string): unknown {
    return path.split(".").reduce((value, key) => value?.[key], source);
}

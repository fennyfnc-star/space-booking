import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  build: {
    // Output JS to assets/js, CSS to assets/css (via cssCodeSplit)
    outDir: "assets",
    emptyOutDir: false,
    rollupOptions: {
      input: {
        "booking-app": path.resolve(__dirname, "src/main.tsx"),
        "lookup-app": path.resolve(__dirname, "src/lookup-main.tsx"),
      },
      output: {
        dir: "assets",
        entryFileNames: "js/[name].js",
        chunkFileNames: "js/[name]-[hash].js",
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith(".css")) {
            return "css/[name][extname]";
          }
          return "js/[name][extname]";
        },
      },
    },
  },
});

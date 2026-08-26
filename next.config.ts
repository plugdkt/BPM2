import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Produces .next/standalone/server.js — a self-contained Node entry point
  // that iisnode can launch directly (see DEPLOY.md).
  output: "standalone",
};

export default nextConfig;

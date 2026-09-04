import type { NextConfig } from "next";

const wordpressHostname = process.env.WORDPRESS_HOSTNAME || "localhost";

const nextConfig: NextConfig = {
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || undefined,
  images: {
    remotePatterns: [
      {
        protocol: "https",
        hostname: wordpressHostname,
      },
      {
        protocol: "http",
        hostname: wordpressHostname,
      },
    ],
  },
};

export default nextConfig;

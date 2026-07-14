const configuredDistDir = process.env.NEXT_DIST_DIR?.trim();
const distDir = configuredDistDir || '.next';

if (!/^(?!\.{1,2}$)[A-Za-z0-9._-]+$/.test(distDir)) {
  throw new Error('NEXT_DIST_DIR must be a project-local directory name.');
}

/** @type {import('next').NextConfig} */
const nextConfig = {
  distDir,
  poweredByHeader: false,
  reactStrictMode: true,
  skipTrailingSlashRedirect: true,
  images: {
    unoptimized: true,
  },
  experimental: {
    optimizePackageImports: ['lucide-react'],
  },
};

export default nextConfig;

import type { Metadata } from "next";
import { Geist } from "next/font/google";
import Link from "next/link";
import "./globals.css";

const geistSans = Geist({ variable: "--font-geist-sans", subsets: ["latin"] });

export const metadata: Metadata = {
  title: { default: "HSP Blog", template: "%s | HSP Blog" },
  description: "Headless blog powered by the HSP Delivery API.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className={`${geistSans.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col bg-white text-gray-900">
        <header className="border-b border-gray-200 px-6 py-4">
          <nav className="max-w-3xl mx-auto flex items-center gap-6 text-sm font-medium">
            <Link href="/posts" className="text-blue-600 hover:underline">
              Blog
            </Link>
          </nav>
        </header>
        <main className="flex-1 max-w-3xl mx-auto w-full px-6 py-10">{children}</main>
        <footer className="border-t border-gray-200 px-6 py-4 text-center text-xs text-gray-400">
          Powered by HSP Delivery API
        </footer>
      </body>
    </html>
  );
}

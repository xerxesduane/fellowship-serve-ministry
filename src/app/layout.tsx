import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

const siteUrl = "https://shape-fellowship-dubai.vercel.app";
const siteDescription = "Discover your Spiritual Gifts, Heart, Abilities, Personality, and Experiences, then build a ministry profile for serving at Fellowship Dubai.";

const structuredData = {
  "@context": "https://schema.org",
  "@type": "WebApplication",
  name: "SERVE – S.H.A.P.E. Discovery Tool",
  url: siteUrl,
  description: siteDescription,
  applicationCategory: "LifestyleApplication",
  publisher: {
    "@type": "Organization",
    name: "Fellowship Dubai",
    url: "https://fellowshipdubai.com/",
    logo: {
      "@type": "ImageObject",
      url: `${siteUrl}/fellowship-logo.jpeg`,
    },
  },
};

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: {
    default: "SERVE – S.H.A.P.E. Discovery Tool | Fellowship Dubai",
    template: "%s | Fellowship Dubai",
  },
  description: siteDescription,
  applicationName: "SERVE – S.H.A.P.E. Discovery Tool",
  keywords: ["Fellowship Dubai", "SHAPE assessment", "spiritual gifts", "church ministry", "serving", "Dubai church"],
  authors: [{ name: "Fellowship Dubai", url: "https://fellowshipdubai.com/" }],
  creator: "Fellowship Dubai",
  publisher: "Fellowship Dubai",
  alternates: { canonical: "/" },
  icons: {
    icon: [{ url: "/icon.svg", type: "image/svg+xml" }],
    shortcut: "/icon.svg",
  },
  robots: {
    index: true,
    follow: true,
  },
  openGraph: {
    type: "website",
    locale: "en_AE",
    url: "/",
    siteName: "SERVE – S.H.A.P.E. Discovery Tool",
    title: "SERVE – S.H.A.P.E. Discovery Tool",
    description: "Explore your Spiritual Gifts, Heart, Abilities, Personality, and Experiences, then build a shareable ministry profile.",
  },
  twitter: {
    card: "summary_large_image",
    title: "Discover How God SHAPED You to Serve",
    description: "Build your personal S.H.A.P.E. ministry profile with Fellowship Dubai.",
  },
  category: "religion and spirituality",
  formatDetection: { email: false, address: false, telephone: false },
};

export const viewport: Viewport = {
  themeColor: "#12263f",
  colorScheme: "light",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData) }}
        />
        {children}
      </body>
    </html>
  );
}

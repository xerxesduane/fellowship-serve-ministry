import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "SERVE – S.H.A.P.E. Discovery Tool",
    short_name: "SERVE SHAPE",
    description: "Discover your Spiritual Gifts, Heart, Abilities, Personality, and Experiences, then build a ministry profile for serving at Fellowship Dubai.",
    start_url: "/",
    display: "standalone",
    background_color: "#f5f1e8",
    theme_color: "#12263f",
    icons: [{ src: "/icon.svg", sizes: "any", type: "image/svg+xml" }],
  };
}

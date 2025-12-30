/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./*.php", "./app/**/*.php"],
  theme: {
    extend: {
      fontFamily: {
        sans: [
          "Inter",
          "ui-sans-serif",
          "system-ui",
          "-apple-system",
          "Segoe UI",
          "Roboto",
          "Helvetica",
          "Arial",
          "sans-serif",
        ],
      },
    },
  },
  plugins: [require("daisyui")],
  daisyui: {
    themes: ["corporate"],
    logs: false,
  },
};


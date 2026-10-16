// True below 1280px, where DetailPanel slides over (matches DetailPanel.css)
export function panelSlidesOver() {
  return window.matchMedia('(max-width: 1279px)').matches
}

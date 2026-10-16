import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'

// Photos fade in as they finish loading instead of popping in (img rules in index.css).
// "load" doesn't bubble, so it's caught on the way down (true). A photo that fails stays visible as the browser shows it.
document.documentElement.classList.add('fade-images')
document.addEventListener(
  'load',
  (event) => {
    if (event.target.tagName === 'IMG') event.target.classList.add('is-loaded')
  },
  true,
)
document.addEventListener(
  'error',
  (event) => {
    if (event.target.tagName === 'IMG') event.target.classList.add('is-loaded')
  },
  true,
)

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

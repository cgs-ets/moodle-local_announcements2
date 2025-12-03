import { useState } from "react"
import { createUrl } from '../utils/index'

interface FetchState {
  error: boolean | null;
  loading: boolean;
  response: any;
}

interface FetchOptions {
  method?: string;
  body?: object;
  query?: object;
}

export function useAjax() {

  const [data, setData] = useState<FetchState>({
    response: null,
    error: false,
    loading: false,
  })

  const ajax = async (options: FetchOptions = { method: "GET", body: {}, query: {} }, url: string) => {
    if (options.query || options.body) {
      setData({ response: null, error: null, loading: true })
      fetch(createUrl(url, options.query), {
        method: options.method || "GET",
        headers: {
          "Content-Type": "application/json",
        },
        body: options.method !== "GET" ? JSON.stringify(options.body) : null,
      })
      .then(async response => {
        const data = await response.json()
        setData({
          response: data,
          error: data.error,
          loading: false,
        })
      })
      .catch(error => {
        setData({
          response: error,
          error: true,
          loading: false,
        })
      })
    }
  }

  return [ data.response, data.error, data.loading, ajax, setData ];
}
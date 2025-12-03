import { useState } from "react"
import { createUrl } from "../utils"

interface FetchState {
  error: boolean;
  success: boolean;
  loading: boolean;
  data: any;
}

interface FetchOptions {
  method?: string;
  body?: object;
  query?: object;
}

function useFetch(): {
  call: (options?: FetchOptions, url?: string) => Promise<any>,
  state: FetchState,
  setState: React.Dispatch<React.SetStateAction<FetchState>>
} {

  const [state, setState] = useState<FetchState>({
    error: false,
    success: false,
    loading: false,
    data: null,
  });

  const call = async (options?: FetchOptions, url?: string): Promise<any> => {

    setState({ error: false, success: false, loading: true, data: null });

    const method = options?.method || "GET";
    
    try {
      const response = await fetch(createUrl(url, options?.query), {
        method,
        headers: {
          "Content-Type": "application/json",
        },
        body: method !== "GET" ? JSON.stringify(options?.body || '') : null,
      });

      if (!response.ok) {
        throw new Error(`Network response was not ok: ${response.statusText}`);
      }

      const result = await response.json();
      setState({ error: false, success: true, loading: false, data: result });
      return result;

    } catch (error: any) {
      setState({ error: true, success: false, loading: false, data: error.message });
      return Promise.reject(error);
    }
  };

  return { call, state, setState };
}

export default useFetch;

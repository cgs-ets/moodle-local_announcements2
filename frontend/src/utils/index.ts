
declare global {
  interface Window {
      appdata:any;
  }
}

const getConfig = () => {
  if (!window.appdata) {
    console.log("No appdata found!")
    return {
      roles: [],
      user: null,
      wwwroot: '',
      sesskey: '',
    }
  }
  return window.appdata!.config
};

const queryString = (params: any) => {
  return Object.keys(params)
    .map(key => `${key}=${params[key]}`)
    .join("&")
}

const createUrl = (url?: string, queryOptions?: any) => {
  if (!url) {
    url = getConfig().wwwroot + '/local/announcements2/service.php';
  }
  queryOptions = queryOptions || {}
  queryOptions.sesskey = getConfig().sesskey
  return url + "?" + queryString(queryOptions)
}

const fetchData = async (options: any, url?: string) => {
  const defaultOptions = { 
    method: "GET", 
    body: {}, 
    query: {} 
  };
  const mergedOptions = { ...defaultOptions, ...options };

  if (! (mergedOptions.query || mergedOptions.body)) {
    throw new Error('Body or query required.'); 
  }

  const response = await fetch(createUrl(url, mergedOptions.query), {
    method: mergedOptions.method || "GET",
    headers: {
      "Content-Type": "application/json",
    },
    body: mergedOptions.method !== "GET" ? JSON.stringify(mergedOptions.body) : null,
  });
  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'An error occurred.');
  }

  return data;
};

const statuses = {
  draft: 0,
  saved: 1,
  inreview: 2,
  approved: 3,
  cancelled: 4,
}

export { fetchData, getConfig, queryString, createUrl, statuses };
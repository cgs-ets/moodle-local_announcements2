import { createBrowserRouter, RouterProvider } from "react-router-dom";
import "inter-ui/inter.css";
import './App.css'
import '@mantine/tiptap/styles.css';
import { LocalizationProvider } from '@mui/x-date-pickers';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs'
import 'dayjs/locale/en-gb';
import { Create } from "./pages/Announcement/Create";
import { Edit } from "./pages/Announcement/Edit";
import { List } from "./pages/List/List";
import { Moderation } from "./pages/Moderation/Moderation";



function App() { 

  const router = createBrowserRouter(
    [
      { path: "/", element: <List /> },
      { path: "/index.php", element: <List /> },

      { path: "new", element: <Create /> },
      { path: "moderation", element: <Moderation /> },
      { path: ":id", element: <Edit /> },
    ],
    { basename: '/local/announcements2' }
  );

  return (
    <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="en-gb">
    <div className="page">
      <RouterProvider router={router} />
    </div>

    </LocalizationProvider>
  );
}

export default App

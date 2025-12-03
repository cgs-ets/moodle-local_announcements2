import useFetch from "../../hooks/useFetch";
import { useSearchParams } from "react-router-dom";

export function Moderation() {

  const [searchParams, setSearchParams] = useSearchParams();
  const api = useFetch();

  return (
    <div>

   
       Well.. here it is...

    </div>
  )
}
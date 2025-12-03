
import { create } from 'zustand'
import { exportActivityHash } from '../utils/activityHash'

export type ViewStateProps = {
  readOnly: boolean;
  editable: boolean;
}

type State = {
  oldhash: string,
  hash: string,
  formloaded: boolean,
  studentsloaded: boolean,
  filesloaded: boolean,
  haschanges: boolean,
  reloadstulist: boolean,
  savedtime: number,
  viewStateProps: ViewStateProps,
}



type StateStore = State & {
  setState: (newState: State | null) => void,
  reset: () => void,
  reloadStudents: () => void,
  baselineHash: () =>  void,
  clearHash: () =>  void,
  updateHash: () => void,
  resetHash: () => void,
  setFormLoaded: () => void,
  setStudentsLoaded: (loaded: boolean) => void,
  setFilesLoaded: () => void,
  updateSavedTime: () => void,
  updateViewStateProps: (props: ViewStateProps) => void,
}

const formStateInit = {
  oldhash: '',
  hash: '',
  formloaded: false,
  studentsloaded: false,
  filesloaded: false,
  haschanges: false,
  reloadstulist: false,
  savedtime: 0,
  viewStateProps: {
    readOnly: true,
    editable: false,
  } as ViewStateProps,
}
const useStateStore = create<StateStore>((set, get) => ({
  ...formStateInit,
  setState: (newState) => set(newState ? newState : formStateInit),
  reset: () => set(formStateInit),
  reloadStudents: () => set({reloadstulist: true}),
  baselineHash: () => {
    console.log("Baselining hash")
    const hash = exportActivityHash()
    set({
      oldhash: hash, 
      hash: hash,
    })
  },
  clearHash: () => {
    console.log("Clearing hash")
    set({
      oldhash: '', 
      hash: '',
      haschanges: false,
    })
  },
  updateHash: () => {
    console.log("Updating hash")
    const hash = exportActivityHash()
    set((state: State) => {
      return { 
        hash: hash, 
        haschanges: (hash !== state.oldhash) 
      }
    })
  },
  resetHash: () => {
    const hash = exportActivityHash()
    set((state: State) => ({
      hash: state.oldhash,
      haschanges: (hash !== state.oldhash) ,
    }))
  },
  setFormLoaded: () => set({formloaded: true}),
  setStudentsLoaded: (loaded: boolean) => set({studentsloaded: loaded}),
  setFilesLoaded: () => set({filesloaded: true}),
  updateSavedTime: () => set({savedtime: Date.now()}),
  updateViewStateProps: (vals: ViewStateProps) => set({viewStateProps: vals}),
}))



export { useStateStore };